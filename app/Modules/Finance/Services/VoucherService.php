<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Models\Company;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Basic Payment Voucher PDF (M03-06 basic; Prompt 3 moves it onto the template engine). Rendered after the
 * payment is posted, stored in the Document Centre and frozen (an issued document never changes).
 *
 * @see SRS M03-06, M01-05, M06-03
 */
final class VoucherService
{
    public function __construct(private readonly DocumentStore $documents) {}

    public function generate(Payment $payment, User $actor): ?int
    {
        try {
            $html = view('finance.payment-voucher', $this->data($payment))->render();
            $path = tempnam(sys_get_temp_dir(), 'pv').'.pdf';
            file_put_contents($path, Pdf::loadHTML($html)->setPaper('a4')->output());
            $file = new UploadedFile($path, $payment->payment_no.'.pdf', 'application/pdf', null, true);
            $stored = $this->documents->attach($file, $actor, [
                'title' => 'Payment voucher '.$payment->payment_no, 'classification' => 'confidential', 'retention_rule' => 'finance_7y',
            ], new LinkTarget('payment', $payment->id, 'issued_document'));
            $payment->forceFill(['voucher_document_id' => $stored->documentId])->save();
            @unlink($path);

            return $stored->documentId;
        } catch (Throwable $e) {
            // The payment is posted regardless; the voucher can be regenerated.
            Log::error('Payment voucher generation failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<string, mixed> */
    public function data(Payment $payment): array
    {
        $payment->loadMissing(['allocations', 'bankAccount.companyAccount']);

        return [
            'company' => Company::current(),
            'payment' => $payment,
            'lines' => $payment->allocations->map(function ($a): array {
                $target = $a->target_type === 'bill' ? Bill::query()->with('vendor')->find($a->target_id) : Claim::query()->find($a->target_id);

                return [
                    'ref' => $target instanceof Bill ? "{$target->bill_no} ({$target->vendor_invoice_no})" : ($target !== null ? $target->claim_no : '#'.$a->target_id),
                    'description' => $target instanceof Bill ? ($target->description ?? 'Vendor bill') : ($target !== null ? $target->purpose : ''),
                    'amount' => $a->amount->getAmount()->__toString(),
                ];
            })->all(),
            'approvedBy' => $payment->approved_by ? User::query()->find($payment->approved_by)?->name : null,
            'preparedBy' => User::query()->find($payment->created_by)?->name,
        ];
    }
}
