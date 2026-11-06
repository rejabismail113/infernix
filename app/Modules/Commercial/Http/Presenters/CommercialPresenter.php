<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Http\Presenters;

use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Models\User;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Models\CommercialDocument;
use App\Modules\Commercial\Models\DocumentLine;
use App\Modules\Commercial\Models\Receipt;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Facades\DB;

/**
 * Shapes commercial documents for Inertia. Money is always a decimal string. Internal notes are only sent to
 * users who may edit the document; issued-snapshot data is what an issued document shows.
 *
 * @see SRS M03-01, M03-03, M03-07, M11-05
 */
final class CommercialPresenter
{
    /** @return array<string, mixed> */
    public static function row(CommercialDocument $d): array
    {
        return [
            'id' => $d->id, 'type' => $d->type->value, 'number' => $d->displayNo(), 'document_no' => $d->document_no, 'revision_no' => $d->revision_no,
            'client' => $d->counterpartyName(), 'title' => $d->title, 'status' => $d->status->value, 'settlement_status' => $d->settlement_status?->value,
            'issue_date' => $d->issue_date?->toDateString(), 'due_date' => $d->due_date?->toDateString(), 'valid_until' => $d->valid_until?->toDateString(),
            'total' => self::m($d->total), 'outstanding' => $d->type === DocumentType::Invoice && $d->status->isIssued() ? self::m($d->outstanding()) : null,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(CommercialDocument $d, User $viewer): array
    {
        $d->loadMissing('lines');
        $project = $d->project_id !== null ? app(ProjectDirectory::class)->snapshot($d->project_id) : null;

        return self::row($d) + [
            'client_id' => $d->client_id, 'counterparty_id' => $d->counterparty_id, 'counterparty_type' => $d->counterparty_type, 'counterparty' => $d->counterparty_snapshot,
            'external_ref' => $d->external_ref, 'subject' => $d->subject, 'body' => $d->body, 'effective_date' => $d->effective_date?->toDateString(), 'project' => $project,
            'project_id' => $d->project_id, 'terms' => $d->terms,
            'internal_notes' => $viewer->can('update', $d) ? $d->internal_notes : null,
            'subtotal' => self::m($d->subtotal), 'discount_total' => self::m($d->discount_total), 'tax_total' => self::m($d->tax_total),
            'amount_received' => self::m($d->amount_received), 'amount_credited' => self::m($d->amount_credited),
            'current_level' => $d->current_level, 'void_reason' => $d->void_reason, 'voided_at' => $d->voided_at?->toIso8601String(),
            'issued_at' => $d->issued_at?->toIso8601String(), 'source_type' => $d->source_type, 'source_id' => $d->source_id,
            'related_document_id' => $d->related_document_id, 'supersedes_id' => $d->supersedes_id,
            'pdf_document_id' => $d->pdf_document_id, 'void_pdf_document_id' => $d->void_pdf_document_id,
            'template' => $d->templateVersion !== null ? $d->templateVersion->template->code.' v'.$d->templateVersion->version : null,
            'lines' => $d->lines->map(fn (DocumentLine $l): array => self::line($l))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function line(DocumentLine $l): array
    {
        return [
            'id' => $l->id, 'line_no' => $l->line_no, 'description' => $l->description, 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'), 'unit' => $l->unit,
            'unit_price' => self::m($l->unit_price), 'discount' => self::m($l->discount), 'tax_code_id' => $l->tax_code_id, 'tax_rate' => (string) $l->tax_rate,
            'net_amount' => self::m($l->net_amount), 'tax_amount' => self::m($l->tax_amount), 'line_total' => self::m($l->line_total),
            'revenue_account_id' => $l->revenue_account_id, 'source_line_id' => $l->source_line_id, 'milestone_id' => $l->milestone_id,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function approvals(CommercialDocument $d): array
    {
        return DB::table('approval_steps as s')->join('users as u', 'u.id', '=', 's.approver_id')->where('s.entity_type', $d->type->value)->where('s.entity_id', $d->id)
            ->orderBy('s.id')->get(['s.level', 's.owner_override', 's.delegation_id', 's.reason', 's.decided_at', 'u.name'])
            ->map(fn ($s): array => ['level' => (int) $s->level, 'approver' => $s->name, 'decision' => 'approved', 'owner_override' => (bool) $s->owner_override, 'delegated' => $s->delegation_id !== null, 'reason' => $s->reason, 'decided_at' => (string) $s->decided_at])->all();
    }

    /** @return array<string, mixed> */
    public static function receipt(Receipt $r): array
    {
        return [
            'id' => $r->id, 'receipt_no' => $r->receipt_no, 'receipt_date' => $r->receipt_date->toDateString(), 'amount' => self::m($r->amount),
            'unapplied_amount' => self::m($r->unapplied_amount), 'method' => $r->method, 'reference' => $r->reference, 'payer_name' => $r->payer_name,
            'status' => $r->status, 'pdf_document_id' => $r->pdf_document_id, 'void_reason' => $r->void_reason, 'journal_id' => $r->journal_id,
        ];
    }

    private static function m(BrickMoney $m): string
    {
        return (string) $m->getAmount();
    }
}
