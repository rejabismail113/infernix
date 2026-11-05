<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Documents\Contracts\IssuedDocumentRenderer;
use App\Kernel\Documents\IssuedDocumentRequest;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Models\User;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payment Voucher (M03-06): after a payment is posted, the M03 template engine renders the PV (payee,
 * allocations, approvals, signatory), registers it under the payment's PV number and stores it frozen in the
 * Document Centre — through a kernel contract, so M01 does not depend on M03.
 *
 * @see SRS M03-06, M01-05, M06-03
 */
final class VoucherService
{
    public function __construct(private readonly IssuedDocumentRenderer $renderer) {}

    public function generate(Payment $payment, User $actor): ?int
    {
        try {
            $id = $this->renderer->issue($this->request($payment, $actor));
            $payment->forceFill(['voucher_document_id' => $id])->save();

            return $id;
        } catch (Throwable $e) {
            // The payment is posted regardless; the voucher can be regenerated.
            Log::error('Payment voucher generation failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function request(Payment $payment, User $actor): IssuedDocumentRequest
    {
        $payment->loadMissing(['allocations', 'bankAccount.companyAccount']);
        $lines = $payment->allocations->map(function ($a): array {
            $target = $a->target_type === 'bill' ? Bill::query()->with('vendor')->find($a->target_id) : Claim::query()->find($a->target_id);

            return [
                'ref' => $target instanceof Bill ? "{$target->bill_no} ({$target->vendor_invoice_no})" : ($target !== null ? $target->claim_no : '#'.$a->target_id),
                'description' => $target instanceof Bill ? ($target->description ?? 'Vendor bill') : ($target !== null ? $target->purpose : ''),
                'amount' => (string) $a->amount->getAmount(),
            ];
        })->values()->all();

        return new IssuedDocumentRequest(
            type: 'payment_voucher', documentNo: $payment->payment_no, sourceType: 'payment', sourceId: $payment->id,
            counterpartyType: 'payee', counterparty: ['name' => $payment->payee_name, 'type' => $payment->payee_type],
            lines: $lines, total: (string) $payment->amount->getAmount(), issueDate: $payment->payment_date,
            link: new LinkTarget('payment', $payment->id, 'issued_document'), actor: $actor,
            extra: [
                'method' => strtoupper($payment->method), 'reference' => $payment->reference, 'memo' => $payment->memo,
                'bank' => $payment->bankAccount->companyAccount->bank_name.' '.$payment->bankAccount->companyAccount->maskedAccountNo(),
                'prepared_by' => User::query()->find($payment->created_by)?->name, 'approved_by' => $payment->approved_by ? User::query()->find($payment->approved_by)?->name : null,
                'owner_override' => $payment->owner_override,
            ],
        );
    }
}
