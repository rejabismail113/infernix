<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Http\Controllers;

use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Commercial\EInvoice\EInvoiceGateway;
use App\Modules\Commercial\EInvoice\EInvoiceOutbox;
use App\Modules\Commercial\EInvoice\EInvoiceService;
use App\Modules\Commercial\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * e-Invoice settings and outbox (§6.2): flag state, the accountant-verification banner, submission status, event
 * log and manual retry.
 *
 * @see SRS §6.2
 */
final class EInvoiceController
{
    use HandlesDomainErrors;

    public function index(Request $request, PermissionService $permissions): Response
    {
        $user = $this->user($request);
        abort_unless($permissions->can($user, PermissionAction::View, 'invoices'), 403);
        $rows = EInvoiceOutbox::query()->orderByDesc('id')->limit(200)->get();
        $numbers = Invoice::query()->whereIn('id', $rows->pluck('invoice_id'))->pluck('document_no', 'id');

        return Inertia::render('Commercial/EInvoice/Index', [
            'enabled' => EInvoiceService::enabled(),
            'gateway' => app(EInvoiceGateway::class)->name(),
            'rows' => $rows->map(fn (EInvoiceOutbox $r): array => ['id' => $r->id, 'invoice_id' => $r->invoice_id, 'invoice_no' => $numbers[$r->invoice_id] ?? null, 'status' => $r->status, 'attempts' => $r->attempts,
                'next_attempt_at' => $r->next_attempt_at?->toDateTimeString(), 'last_error' => $r->last_error, 'external_uuid' => $r->external_uuid, 'accepted_at' => $r->accepted_at?->toDateTimeString(),
                'events' => DB::table('einvoice_events')->where('outbox_id', $r->id)->orderBy('id')->get(['event', 'detail', 'created_at'])->map(fn ($e): array => ['event' => $e->event, 'detail' => json_decode((string) $e->detail, true), 'at' => (string) $e->created_at])->all()]),
            'can' => ['retry' => $permissions->can($user, PermissionAction::Edit, 'invoices')],
        ]);
    }

    public function retry(Request $request, EInvoiceOutbox $outbox, EInvoiceService $service, PermissionService $permissions): RedirectResponse
    {
        abort_unless($permissions->can($this->user($request), PermissionAction::Edit, 'invoices'), 403);

        return $this->attempt(fn () => $service->retry($outbox, $this->user($request)), __('Queued for resubmission.'));
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
