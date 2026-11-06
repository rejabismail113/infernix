<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Notifications\NotificationService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Search\SearchIndexer;
use App\Modules\Procurement\Exceptions\ProcurementRejected;
use App\Modules\Procurement\Models\Subscription;
use App\Modules\Procurement\Models\SubscriptionReview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SaaS subscriptions (M10-04/05). The 90/60/30-day alert raises a REVIEW TASK and a notification — never a
 * transaction, bill or journal (WF-06, UAT-12). Finance records the supplier invoice/payment when it arrives;
 * it is then LINKED to the subscription.
 *
 * @see SRS M10-04, M10-05, WF-06, UAT-12
 */
final class SubscriptionService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly FinanceRecords $finance,
        private readonly StaffDirectory $staff,
        private readonly SearchIndexer $search,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function save(?Subscription $s, array $data, User $actor): Subscription
    {
        $fields = array_intersect_key($data, array_flip(['vendor_id', 'product', 'plan', 'license_owner_staff_id', 'seats', 'currency', 'billing_cycle', 'start_date', 'renewal_date', 'project_id', 'notes']))
            + ['auto_renew' => (bool) ($data['auto_renew'] ?? true), 'price' => Money::myr((string) $data['price'])];
        if ($s === null) {
            $s = Subscription::query()->create($fields + ['company_id' => $actor->company_id, 'status' => 'active', 'created_by' => $actor->id]);
        } else {
            if ($s->status === 'cancelled') {
                throw new ProcurementRejected('A cancelled subscription is read-only.');
            }
            $s->forceFill($fields)->save();
        }
        $this->search->index('subscription', $s->id, "{$s->product} ".($s->plan ?? ''), (string) $s->notes, 'Procurement');

        return $s;
    }

    /**
     * Daily scan: for every active subscription inside the alert window, make sure ONE review task exists for its
     * renewal date and send the reminder for today's threshold. Returns the number of reviews opened.
     */
    public function runRenewalAlerts(): int
    {
        $opened = 0;
        Subscription::query()->with('vendor')->whereIn('status', ['active', 'under_review'])->orderBy('renewal_date')->each(function (Subscription $s) use (&$opened): void {
            $days = (int) today()->diffInDays($s->renewal_date, false);
            $threshold = $this->notifications->thresholdFor('SubscriptionRenewalDue', max(0, $days));
            if ($threshold === null || $days < -7) {
                return;
            }
            DB::transaction(function () use ($s, $threshold, &$opened): void {
                $review = SubscriptionReview::query()->firstOrCreate(['subscription_id' => $s->id, 'renewal_date' => $s->renewal_date->toDateString()], ['alert_level' => $threshold, 'status' => 'open']);
                if ($review->wasRecentlyCreated) {
                    $opened++;
                    $s->forceFill(['status' => 'under_review'])->save();
                } elseif ($review->status === 'open' && $review->alert_level !== $threshold) {
                    $review->forceFill(['alert_level' => $threshold])->save();
                }
            });
            $owner = $s->license_owner_staff_id !== null ? ($this->staff->snapshot($s->license_owner_staff_id)['user_id'] ?? null) : null;
            $this->notifications->notifyBefore('SubscriptionRenewalDue', max(0, $days), "subscription:{$s->id}:{$s->renewal_date->toDateString()}",
                "{$s->product} renews in {$days} days", "{$s->product} ({$s->vendor->name}) renews on {$s->renewal_date->toDateString()} at RM ".Money::format($s->price).' per '.str_replace('_', ' ', $s->billing_cycle).
                ($s->auto_renew ? ' (auto-renew).' : '.').' Review it: renew or cancel. No payment has been recorded.', route('procurement.subscriptions.index'), null, array_values(array_filter([$owner])));
        });

        return $opened;
    }

    /** Review decision. "renew" rolls the renewal date forward; "cancel" ends the subscription. Neither books money. */
    public function decide(SubscriptionReview $review, string $decision, ?string $note, User $actor): void
    {
        DB::transaction(function () use ($review, $decision, $note, $actor): void {
            /** @var SubscriptionReview $r */
            $r = SubscriptionReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($r->status !== 'open') {
                throw new ProcurementRejected('This review is already decided.');
            }
            /** @var Subscription $s */
            $s = Subscription::query()->lockForUpdate()->findOrFail($r->subscription_id);
            $r->forceFill(['status' => $decision, 'decision_note' => $note, 'decided_by' => $actor->id, 'decided_at' => now()])->save();
            if ($decision === 'renew') {
                $s->forceFill(['status' => 'active', 'renewal_date' => $s->nextRenewal($s->renewal_date)->toDateString()])->save();
            } else {
                $s->forceFill(['status' => 'cancelled', 'auto_renew' => false])->save();
            }
            Audit::log('review_'.$decision, $s, null, ['renewal_date' => $r->renewal_date->toDateString()], ['renewal_date' => $s->renewal_date->toDateString(), 'status' => $s->status], $note, $actor);
        });
    }

    /** The supplier invoice / payment recorded in M01, linked to the subscription (the only money event — WF-06). */
    public function linkFinance(Subscription $s, string $sourceType, int $sourceId, User $actor): void
    {
        if (! in_array($sourceType, ['bill', 'transaction', 'payment'], true)) {
            throw new ProcurementRejected('Link the supplier bill, expense or payment.');
        }
        $this->finance->link('subscription', $s->id, $sourceType, $sourceId, $sourceType === 'payment' ? 'payment' : 'renewal', $actor);
    }

    public function cancel(Subscription $s, string $reason, User $actor): void
    {
        $s->forceFill(['status' => 'cancelled', 'auto_renew' => false])->save();
        SubscriptionReview::query()->where('subscription_id', $s->id)->where('status', 'open')->update(['status' => 'cancel', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $reason]);
        Audit::log('cancel', $s, null, null, null, $reason, $actor);
    }

    public static function annualised(Subscription $s): string
    {
        $price = $s->price->getAmount();

        return (string) match ($s->billing_cycle) {
            'monthly' => $price->multipliedBy(12), 'quarterly' => $price->multipliedBy(4), default => $price,
        };
    }

    public static function daysTo(Carbon $date): int
    {
        return (int) today()->diffInDays($date, false);
    }
}
