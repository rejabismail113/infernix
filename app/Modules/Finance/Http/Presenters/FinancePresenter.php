<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Presenters;

use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Identity\Models\User;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Models\ClaimLine;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Transaction;
use Brick\Money\Money;
use Illuminate\Support\Facades\Gate;

/**
 * Model → Inertia payload. Money is always a decimal string. Clinical notes are only included for users
 * allowed to see them (HR/OWN/claimant); everyone else gets `clinical_note_masked = true`.
 *
 * @see SRS §2.1, CLAUDE.md rule 1
 */
final class FinancePresenter
{
    public static function m(?Money $m): ?string
    {
        return $m === null ? null : (string) $m->getAmount();
    }

    /** @return array<string, mixed> */
    public static function journal(Journal $j, bool $withLines = false): array
    {
        $out = [
            'id' => $j->id, 'journal_no' => $j->journal_no, 'posting_date' => $j->posting_date->toDateString(), 'status' => $j->status->value,
            'source_type' => $j->source_type, 'source_id' => $j->source_id, 'memo' => $j->memo, 'reversal_of_id' => $j->reversal_of_id, 'reversed_by_id' => $j->reversed_by_id,
            'created_by' => $j->relationLoaded('creator') ? $j->creator->name : null, 'posted_at' => $j->posted_at?->toIso8601String(),
        ];
        if ($withLines) {
            $out['lines'] = $j->lines->map(fn ($l): array => ['id' => $l->id, 'line_no' => $l->line_no, 'account' => $l->account->label(), 'account_id' => $l->account_id, 'debit' => self::m($l->debit), 'credit' => self::m($l->credit), 'description' => $l->description])->all();
            $out['total'] = self::m($j->lines->reduce(fn (?Money $s, $l) => $s === null ? $l->debit : $s->plus($l->debit)));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function transaction(Transaction $t): array
    {
        return [
            'id' => $t->id, 'transaction_no' => $t->transaction_no, 'direction' => $t->direction, 'inflow_type' => $t->inflow_type?->value,
            'payment_source' => $t->payment_source?->value, 'capitalization_class' => $t->capitalization_class?->value, 'transaction_date' => $t->transaction_date->toDateString(),
            'gross_amount' => self::m($t->gross_amount), 'counterparty_name' => $t->counterparty_name, 'description' => $t->description, 'bank_reference' => $t->bank_reference,
            'tax_treatment' => $t->tax_treatment->value, 'status' => $t->status->value, 'journal_id' => $t->journal_id, 'reversal_journal_id' => $t->reversal_journal_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function claim(Claim $c, ?User $viewer = null, bool $withLines = false): array
    {
        $out = [
            'id' => $c->id, 'claim_no' => $c->claim_no, 'staff' => $c->relationLoaded('staff') ? $c->staff->name : null, 'staff_user_id' => $c->staff_user_id,
            'purpose' => $c->purpose, 'status' => $c->status->value, 'current_level' => $c->current_level, 'total_amount' => self::m($c->total_amount),
            'approved_amount' => self::m($c->approved_amount), 'paid_amount' => self::m($c->paid_amount), 'outstanding' => self::m($c->outstanding()),
            'submitted_at' => $c->submitted_at?->toIso8601String(), 'approved_at' => $c->approved_at?->toIso8601String(), 'journal_id' => $c->journal_id,
            'rejection_reason' => $c->rejection_reason, 'cost_centre_id' => $c->cost_centre_id, 'project_id' => $c->project_id,
        ];
        if ($withLines && $viewer !== null) {
            $clinical = Gate::forUser($viewer)->allows('viewClinical', $c);
            $docs = app(DocumentStore::class);
            $out['lines'] = $c->lines->map(fn (ClaimLine $l): array => [
                'id' => $l->id, 'line_no' => $l->line_no, 'claim_type_id' => $l->claim_type_id, 'claim_type' => $l->claimType->name, 'claim_type_code' => $l->claimType->code,
                'is_medical' => $l->claimType->is_medical, 'receipt_required' => $l->claimType->receipt_required,
                'incurred_on' => $l->incurred_on->toDateString(), 'description' => $l->description, 'amount' => self::m($l->amount), 'category_id' => $l->category_id,
                'status' => $l->status, 'rejection_reason' => $l->rejection_reason, 'origin' => $l->origin, 'destination' => $l->destination,
                'distance_km' => $l->distance_km, 'rate_snapshot' => self::m($l->rate_snapshot),
                'clinical_note' => $l->claimType->is_medical && $clinical ? $l->clinical_note : null,
                'clinical_note_masked' => $l->claimType->is_medical && ! $clinical,
                'documents' => $docs->linkedTo('claim_line', $l->id),
            ])->all();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function bill(Bill $b, bool $withLines = false): array
    {
        $out = [
            'id' => $b->id, 'bill_no' => $b->bill_no, 'vendor' => $b->relationLoaded('vendor') ? $b->vendor->name : null, 'vendor_id' => $b->vendor_id,
            'vendor_invoice_no' => $b->vendor_invoice_no, 'bill_date' => $b->bill_date->toDateString(), 'due_date' => $b->due_date->toDateString(),
            'total_amount' => self::m($b->total_amount), 'paid_amount' => self::m($b->paid_amount), 'outstanding' => self::m($b->outstanding()),
            'status' => $b->status->value, 'current_level' => $b->current_level, 'tax_treatment' => $b->tax_treatment->value, 'description' => $b->description, 'journal_id' => $b->journal_id,
        ];
        if ($withLines) {
            $out['lines'] = $b->lines->map(fn ($l): array => ['line_no' => $l->line_no, 'description' => $l->description, 'quantity' => $l->quantity, 'unit_price' => self::m($l->unit_price), 'amount' => self::m($l->amount), 'capitalization_class' => $l->capitalization_class->value])->all();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function payment(Payment $p, bool $withAllocations = false): array
    {
        $out = [
            'id' => $p->id, 'payment_no' => $p->payment_no, 'payment_date' => $p->payment_date->toDateString(), 'method' => $p->method, 'reference' => $p->reference,
            'payee_type' => $p->payee_type, 'payee_name' => $p->payee_name, 'amount' => self::m($p->amount), 'status' => $p->status->value, 'current_level' => $p->current_level,
            'journal_id' => $p->journal_id, 'voucher_document_id' => $p->voucher_document_id, 'owner_override' => $p->owner_override, 'override_reason' => $p->override_reason,
            'bank' => $p->relationLoaded('bankAccount') ? $p->bankAccount->label() : null, 'memo' => $p->memo, 'created_by' => $p->created_by,
        ];
        if ($withAllocations) {
            $out['allocations'] = $p->allocations->map(function ($a): array {
                $ref = $a->target_type === 'bill' ? Bill::query()->whereKey($a->target_id)->value('bill_no') : Claim::query()->whereKey($a->target_id)->value('claim_no');

                return ['target_type' => $a->target_type, 'target_id' => $a->target_id, 'reference' => $ref, 'amount' => self::m($a->amount)];
            })->all();
        }

        return $out;
    }
}
