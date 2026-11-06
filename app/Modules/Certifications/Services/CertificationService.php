<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Money\Money;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Search\SearchIndexer;
use App\Modules\Certifications\Exceptions\CertificationRejected;
use App\Modules\Certifications\Models\Certification;
use App\Modules\Certifications\Models\Membership;
use App\Modules\Certifications\Models\Renewal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Certifications & memberships (M09) with a renewal schedule: every expiring record has one OPEN renewal for its
 * due date; renewing rolls the dates forward and opens the next cycle. Renewal cost = LINK to the finance
 * record that paid it (M09-04) — this module never books money.
 *
 * @see SRS M09-01, M09-02, M09-03, M09-04
 */
final class CertificationService
{
    public function __construct(
        private readonly FinanceRecords $finance,
        private readonly StaffDirectory $staff,
        private readonly PermissionService $permissions,
        private readonly SearchIndexer $search,
    ) {}

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $q
     * @return Builder<T>
     */
    public function scoped(Builder $q, User $user, string $resource): Builder
    {
        $scope = $this->permissions->scopeFor($user, $resource, PermissionAction::View);

        return match ($scope) {
            RecordScope::All => $q,
            RecordScope::None => $q->whereRaw('1 = 0'),
            default => $q->where(fn ($w) => $w->where('created_by', $user->id)->orWhere('staff_profile_id', $this->staff->idForUser($user) ?? 0)),
        };
    }

    /** @param  array<string, mixed>  $data */
    public function saveCertification(?Certification $c, array $data, User $actor): Certification
    {
        $this->checkHolder($data);
        $noExpiry = (bool) ($data['no_expiry'] ?? false);
        if (! $noExpiry && empty($data['expiry_date'])) {
            throw new CertificationRejected('Enter the expiry date, or mark the certificate as having no expiry.');
        }
        if (! $noExpiry && ! empty($data['issue_date']) && Carbon::parse((string) $data['expiry_date'])->lt(Carbon::parse((string) $data['issue_date']))) {
            throw new CertificationRejected('Expiry date is before the issue date.');
        }

        return DB::transaction(function () use ($c, $data, $actor, $noExpiry): Certification {
            $fields = array_intersect_key($data, array_flip(['holder_type', 'staff_profile_id', 'name', 'issuer', 'category', 'credential_no', 'issue_date', 'verification_url', 'notes'])) + [
                'no_expiry' => $noExpiry, 'expiry_date' => $noExpiry ? null : $data['expiry_date'],
            ];
            if ($fields['holder_type'] === 'company') {
                $fields['staff_profile_id'] = null;
            }
            if ($c === null) {
                $c = Certification::query()->create($fields + ['company_id' => $actor->company_id, 'status' => 'active', 'created_by' => $actor->id]);
            } else {
                $c->forceFill($fields)->save();
            }
            $this->syncRenewal('certification', $c->id, $c->dueDate());
            $this->search->index('certification', $c->id, "{$c->name} — {$c->issuer}", trim(($c->credential_no ?? '').' '.($c->category ?? '')), 'Certifications');

            return $c;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function saveMembership(?Membership $m, array $data, User $actor): Membership
    {
        $this->checkHolder($data);
        if (Carbon::parse((string) $data['period_end'])->lte(Carbon::parse((string) $data['period_start']))) {
            throw new CertificationRejected('Membership end must be after its start.');
        }

        return DB::transaction(function () use ($m, $data, $actor): Membership {
            $fields = array_intersect_key($data, array_flip(['holder_type', 'staff_profile_id', 'organisation', 'membership_no', 'tier', 'period_start', 'period_end', 'notes'])) + [
                'auto_renew' => (bool) ($data['auto_renew'] ?? false),
                'annual_fee' => isset($data['annual_fee']) && $data['annual_fee'] !== '' ? Money::myr((string) $data['annual_fee']) : null,
            ];
            if ($fields['holder_type'] === 'company') {
                $fields['staff_profile_id'] = null;
            }
            if ($m === null) {
                $m = Membership::query()->create($fields + ['company_id' => $actor->company_id, 'status' => 'active', 'created_by' => $actor->id]);
            } else {
                $m->forceFill($fields)->save();
            }
            $this->syncRenewal('membership', $m->id, $m->period_end);
            $this->search->index('membership', $m->id, "{$m->organisation} membership", (string) $m->membership_no, 'Certifications');

            return $m;
        });
    }

    /** Record a completed renewal: dates roll forward, the cycle closes and the next one opens. */
    public function renew(Renewal $r, string $renewedOn, string $newExpiry, ?string $note, User $actor): Renewal
    {
        return DB::transaction(function () use ($r, $renewedOn, $newExpiry, $note, $actor): Renewal {
            /** @var Renewal $locked */
            $locked = Renewal::query()->lockForUpdate()->findOrFail($r->id);
            if ($locked->status !== 'open') {
                throw new CertificationRejected('This renewal is already closed.');
            }
            if (Carbon::parse($newExpiry)->lte($locked->due_date)) {
                throw new CertificationRejected('The new expiry must be after the current one.');
            }
            $locked->forceFill(['status' => 'renewed', 'renewed_on' => $renewedOn, 'new_expiry_date' => $newExpiry, 'note' => $note, 'decided_by' => $actor->id])->save();
            $item = $locked->renewable() ?? throw new CertificationRejected('The renewed record no longer exists.');
            if ($item instanceof Certification) {
                $item->forceFill(['expiry_date' => $newExpiry, 'status' => 'active'])->save();
            } else {
                $item->forceFill(['period_start' => $locked->due_date->copy()->addDay()->toDateString(), 'period_end' => $newExpiry, 'status' => 'active'])->save();
            }
            $this->syncRenewal($locked->renewable_type, $locked->renewable_id, Carbon::parse($newExpiry));
            Audit::log('renew', $locked, null, ['due_date' => $locked->due_date->toDateString()], ['new_expiry' => $newExpiry], $note, $actor);

            return $locked;
        });
    }

    public function lapse(Renewal $r, string $note, User $actor): void
    {
        DB::transaction(function () use ($r, $note, $actor): void {
            if ($r->status !== 'open') {
                throw new CertificationRejected('This renewal is already closed.');
            }
            $r->forceFill(['status' => 'lapsed', 'note' => $note, 'decided_by' => $actor->id])->save();
            $item = $r->renewable();
            $item?->forceFill(['status' => $item instanceof Certification ? 'expired' : 'lapsed'])->save();
            Audit::log('lapse', $r, null, null, null, $note, $actor);
        });
    }

    /** Renewal fee = link to the bill / expense / claim that paid it (M09-04). */
    public function linkExpense(Renewal $r, string $sourceType, int $sourceId, User $actor): void
    {
        if (! in_array($sourceType, ['transaction', 'bill', 'claim'], true)) {
            throw new CertificationRejected('Link the renewal to the expense, bill or claim that paid it.');
        }
        $this->finance->link('renewal', $r->id, $sourceType, $sourceId, 'renewal', $actor);
    }

    /** Daily: certificates past expiry with no renewal are marked expired. */
    public function markExpired(): int
    {
        return Certification::query()->where('no_expiry', false)->whereIn('status', ['active', 'renewal_in_progress'])->whereDate('expiry_date', '<', today())->update(['status' => 'expired']);
    }

    private function syncRenewal(string $type, int $id, ?Carbon $due): void
    {
        $open = Renewal::query()->where('renewable_type', $type)->where('renewable_id', $id)->where('status', 'open')->first();
        if ($due === null) {
            $open?->forceFill(['status' => 'cancelled', 'note' => 'No expiry'])->save();

            return;
        }
        if ($open !== null && ! $open->due_date->isSameDay($due)) {
            $open->forceFill(['due_date' => $due->toDateString()])->save();

            return;
        }
        if ($open === null) {
            Renewal::query()->firstOrCreate(['renewable_type' => $type, 'renewable_id' => $id, 'due_date' => $due->toDateString()], ['status' => 'open']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function checkHolder(array $data): void
    {
        if (($data['holder_type'] ?? '') === 'staff' && (empty($data['staff_profile_id']) || $this->staff->snapshot((int) $data['staff_profile_id']) === null)) {
            throw new CertificationRejected('Choose the staff member who holds it.');
        }
    }
}
