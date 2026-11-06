<?php

declare(strict_types=1);

namespace App\Modules\Commercial\EInvoice;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Secrets\SecretRedactor;
use App\Modules\Administration\Models\Company;
use App\Modules\Commercial\Models\DocumentLine;
use App\Modules\Commercial\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Outbox processor for e-Invoice submission (§6.2): enqueue once per issued invoice (replay-safe), submit with
 * a stable idempotency key, rate-limit outgoing calls, retry transient errors with exponential backoff, log every
 * step to the append-only event log. Disabled entirely unless features.myinvois is on.
 *
 * @see SRS §6.2, M03-03
 */
final class EInvoiceService
{
    public function __construct(private readonly EInvoiceGateway $gateway, private readonly SecretRedactor $redactor) {}

    public static function enabled(): bool
    {
        return (bool) config('infernix.features.myinvois');
    }

    /** Idempotent: an invoice is enqueued at most once, whatever happens to retries or duplicate events. */
    public function enqueue(int $invoiceId): ?EInvoiceOutbox
    {
        if (! self::enabled()) {
            return null;
        }
        $invoice = Invoice::query()->with('lines')->find($invoiceId);
        if ($invoice === null || $invoice->document_no === null) {
            return null;
        }
        $row = EInvoiceOutbox::query()->firstOrCreate(['invoice_id' => $invoice->id], [
            'idempotency_key' => 'infernix-inv-'.$invoice->id, 'gateway' => $this->gateway->name(), 'payload' => $this->payload($invoice),
            'status' => 'pending', 'next_attempt_at' => now(),
        ]);
        if ($row->wasRecentlyCreated) {
            $this->event($row, 'enqueued', ['invoice_no' => $invoice->document_no]);
        }

        return $row;
    }

    /** Submit due rows (SKIP LOCKED so parallel workers never double-submit). @return int rows processed */
    public function processDue(int $limit = 50): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $processed = 0;
        $ids = EInvoiceOutbox::query()->whereIn('status', ['pending', 'submitting'])->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $perMinute = (int) config('infernix.einvoice.rate_per_minute', 30);
            if (RateLimiter::tooManyAttempts('einvoice-gateway', $perMinute)) {
                break; // stay under the gateway's rate limit; the next run continues
            }
            RateLimiter::hit('einvoice-gateway', 60);
            $this->processOne((int) $id);
            $processed++;
        }

        return $processed;
    }

    public function processOne(int $id): void
    {
        DB::transaction(function () use ($id): void {
            /** @var EInvoiceOutbox|null $row */
            $row = EInvoiceOutbox::query()->whereKey($id)->whereIn('status', ['pending', 'submitting'])->lock('FOR UPDATE SKIP LOCKED')->first();
            if ($row === null) {
                return;
            }
            $row->forceFill(['status' => 'submitting', 'attempts' => $row->attempts + 1, 'submitted_at' => $row->submitted_at ?? now()])->save();
            try {
                $result = $this->gateway->submit($row->payload, $row->idempotency_key);
            } catch (EInvoiceTransientError $e) {
                $this->retryLater($row, $e->getMessage());

                return;
            } catch (Throwable $e) {
                $this->retryLater($row, 'Unexpected: '.$e->getMessage());

                return;
            }
            match ($result['status']) {
                'accepted' => $row->forceFill(['status' => 'accepted', 'external_uuid' => $result['uuid'] ?? null, 'long_id' => $result['long_id'] ?? null, 'accepted_at' => now(), 'last_error' => null, 'next_attempt_at' => null])->save(),
                'rejected' => $row->forceFill(['status' => 'rejected', 'last_error' => $result['message'] ?? 'Rejected', 'next_attempt_at' => null])->save(),
                default => $row->forceFill(['status' => 'submitting', 'next_attempt_at' => now()->addMinutes(5)])->save(),
            };
            $this->event($row, $result['status'], ['uuid' => $result['uuid'] ?? null, 'message' => $result['message'] ?? null, 'attempt' => $row->attempts]);
        });
    }

    /** Manual retry of a failed / rejected row after the data was corrected (audited). */
    public function retry(EInvoiceOutbox $row, User $actor): void
    {
        if (! in_array($row->status, ['failed', 'rejected'], true)) {
            return;
        }
        $invoice = Invoice::query()->with('lines')->findOrFail($row->invoice_id);
        $row->forceFill(['status' => 'pending', 'next_attempt_at' => now(), 'payload' => $this->payload($invoice), 'last_error' => null])->save();
        $this->event($row, 'manual_retry', ['by' => $actor->id]);
        Audit::log('einvoice_retry', 'einvoice_outbox', $row->id, null, ['invoice_id' => $row->invoice_id], null, $actor);
    }

    private function retryLater(EInvoiceOutbox $row, string $error): void
    {
        $max = (int) config('infernix.einvoice.max_attempts', 6);
        $delay = min(3600, (int) config('infernix.einvoice.backoff_seconds', 60) * (2 ** max(0, $row->attempts - 1)));
        $failed = $row->attempts >= $max;
        $row->forceFill(['status' => $failed ? 'failed' : 'pending', 'last_error' => mb_substr($this->redactor->scrub($error), 0, 500), 'next_attempt_at' => $failed ? null : now()->addSeconds($delay)])->save();
        $this->event($row, $failed ? 'failed' : 'retry_scheduled', ['attempt' => $row->attempts, 'error' => mb_substr($this->redactor->scrub($error), 0, 200), 'delay_seconds' => $failed ? null : $delay]);
    }

    /** @param  array<string, mixed>  $detail */
    private function event(EInvoiceOutbox $row, string $event, array $detail): void
    {
        DB::table('einvoice_events')->insert(['outbox_id' => $row->id, 'event' => $event, 'detail' => json_encode($this->redactor->scrubArray($detail)), 'created_at' => now()]);
    }

    /** @return array<string, mixed> minimal UBL-like payload (fields PLACEHOLDER pending accountant review, A-82) */
    private function payload(Invoice $invoice): array
    {
        $company = Company::current();
        $buyer = $invoice->counterparty_snapshot ?? [];

        return [
            'invoice_no' => $invoice->document_no, 'issue_date' => $invoice->issue_date?->toDateString(), 'currency' => 'MYR',
            'supplier' => ['name' => $company->name, 'registration_no' => $company->ssm_no, 'tin' => config('infernix.einvoice.supplier_tin')],
            'buyer' => ['name' => $buyer['name'] ?? null, 'registration_no' => $buyer['registration_no'] ?? null, 'tin' => $buyer['tax_no'] ?? null],
            'lines' => $invoice->lines->map(fn (DocumentLine $l): array => ['description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price->getAmount(),
                'tax_rate' => (string) $l->tax_rate, 'net' => (string) $l->net_amount->getAmount(), 'tax' => (string) $l->tax_amount->getAmount(), 'total' => (string) $l->line_total->getAmount()])->values()->all(),
            'totals' => ['net' => (string) $invoice->subtotal->getAmount(), 'tax' => (string) $invoice->tax_total->getAmount(), 'payable' => (string) $invoice->total->getAmount()],
        ];
    }
}
