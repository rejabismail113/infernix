<?php

declare(strict_types=1);

use App\Modules\Commercial\EInvoice\EInvoiceGateway;
use App\Modules\Commercial\EInvoice\EInvoiceOutbox;
use App\Modules\Commercial\EInvoice\EInvoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/** @see SRS §6.2, M03-03 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
    RateLimiter::clear('einvoice-gateway');
});

it('§6.2: with the flag off nothing is queued or sent', function (): void {
    issuedInvoice('500.00');
    expect(EInvoiceOutbox::query()->count())->toBe(0)->and(app(EInvoiceService::class)->processDue())->toBe(0);
    actingAsSatisfied(ownerUser())->get(route('commercial.einvoice.index'))->assertOk()->assertInertia(fn ($p) => $p->where('enabled', false));
})->group('§6.2');

it('§6.2: issued invoices are queued once, submitted with a stable idempotency key, retried with backoff, and logged', function (): void {
    config(['infernix.features.myinvois' => true, 'infernix.einvoice.stub_fail_times' => 2, 'infernix.einvoice.backoff_seconds' => 60]);
    $inv = issuedInvoice('1200.00', makeClient(['tax_no' => 'C1234567890']));
    $row = EInvoiceOutbox::query()->where('invoice_id', $inv->id)->sole();
    expect($row->idempotency_key)->toBe("infernix-inv-{$inv->id}")->and($row->payload['totals']['payable'])->toBe('1200.00');
    // Replay-safe: a duplicate event / enqueue never creates a second row (also enforced by a UNIQUE index).
    app(EInvoiceService::class)->enqueue($inv->id);
    expect(EInvoiceOutbox::query()->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('einvoice_outbox')->insert(['invoice_id' => $inv->id, 'idempotency_key' => 'x', 'gateway' => 'stub', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);

    $svc = app(EInvoiceService::class);
    $svc->processDue();
    $row->refresh();
    expect($row->status)->toBe('pending')->and($row->attempts)->toBe(1)->and($row->next_attempt_at?->gt(now()->addSeconds(50)))->toBeTrue();
    expect($svc->processDue())->toBe(0); // not due yet
    $this->travel(2)->minutes();
    $svc->processDue();
    $this->travel(5)->minutes();
    $svc->processDue();
    $row->refresh();
    expect($row->status)->toBe('accepted')->and($row->attempts)->toBe(3)->and($row->external_uuid)->toStartWith('STUB-');
    $uuid = $row->external_uuid;
    expect(DB::table('einvoice_events')->where('outbox_id', $row->id)->pluck('event')->all())->toBe(['enqueued', 'retry_scheduled', 'retry_scheduled', 'accepted']);
    expect(fn () => DB::transaction(fn () => DB::table('einvoice_events')->where('outbox_id', $row->id)->delete()))->toThrow(QueryException::class);

    // Same key → same document id (idempotent gateway contract).
    expect(app(EInvoiceGateway::class)->submit($row->payload, $row->idempotency_key)['uuid'])->toBe($uuid);
})->group('§6.2');

it('§6.2: rejected submissions stop, can be retried after correction, and outgoing calls are rate limited', function (): void {
    config(['infernix.features.myinvois' => true, 'infernix.einvoice.rate_per_minute' => 2]);
    $noTin = issuedInvoice('300.00');
    $svc = app(EInvoiceService::class);
    $svc->processDue();
    $row = EInvoiceOutbox::query()->where('invoice_id', $noTin->id)->sole();
    expect($row->status)->toBe('rejected')->and($row->last_error)->toContain('TIN');
    actingAsSatisfied(userWithRoles('STF'))->post(route('commercial.einvoice.retry', $row->id))->assertForbidden();
    actingAsSatisfied(userWithRoles('FIN'))->post(route('commercial.einvoice.retry', $row->id))->assertSessionHasNoErrors();
    expect($row->fresh()->status)->toBe('pending');

    foreach (range(1, 3) as $i) {
        issuedInvoice('100.00', makeClient(['tax_no' => "C00000000{$i}"]));
    }
    RateLimiter::clear('einvoice-gateway');
    expect($svc->processDue())->toBe(2); // 4 due, limit 2 per minute
})->group('§6.2');
