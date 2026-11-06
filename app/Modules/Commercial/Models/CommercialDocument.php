<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Enums\SettlementStatus;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One row of the commercial document register. Each document type is its own model (single table, global
 * type scope) so the permission matrix resource follows the type: quotations / invoices / credit_notes / …
 *
 * Issued rows are immutable at the database level (commercial_documents_immutable trigger, M03-07).
 *
 * @see SRS M03-01, M03-03, M03-07, M03-08
 *
 * @property int $id
 * @property int $company_id
 * @property DocumentType $type
 * @property string|null $document_no
 * @property int $revision_no
 * @property int|null $template_version_id
 * @property array<string, mixed>|null $issuer_profile_snapshot
 * @property string $counterparty_type
 * @property int|null $counterparty_id
 * @property array<string, mixed>|null $counterparty_snapshot
 * @property array{lines?: list<array<string, mixed>>, extra?: array<string, mixed>}|null $lines_snapshot
 * @property int|null $client_id
 * @property int|null $project_id
 * @property int|null $related_document_id
 * @property int|null $supersedes_id
 * @property string|null $title
 * @property Money $subtotal
 * @property Money $discount_total
 * @property Money $tax_total
 * @property Money $total
 * @property Money $amount_received
 * @property Money $amount_credited
 * @property string|null $terms
 * @property string|null $internal_notes
 * @property Carbon|null $valid_until
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property DocumentStatus $status
 * @property SettlementStatus|null $settlement_status
 * @property int $current_level
 * @property string $source_type
 * @property int|null $source_id
 * @property int|null $pdf_document_id
 * @property int|null $void_pdf_document_id
 * @property int|null $journal_id
 * @property int|null $reversal_journal_id
 * @property string|null $void_reason
 * @property Carbon|null $voided_at
 * @property string|null $external_ref
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $verification_token
 * @property Carbon|null $effective_date
 * @property int $created_by
 * @property int|null $approved_by
 * @property Carbon|null $issued_at
 * @property Carbon|null $created_at
 * @property-read Collection<int, DocumentLine> $lines
 */
abstract class CommercialDocument extends Model implements PermissionResource
{
    use Auditable;

    protected $table = 'commercial_documents';

    /** @var list<string> */
    protected $guarded = ['id'];

    abstract public static function documentType(): DocumentType;

    public static function resourceName(): string
    {
        return static::documentType()->resource();
    }

    protected static function booted(): void
    {
        static::addGlobalScope('type', fn (Builder $q) => $q->where('commercial_documents.type', static::documentType()->value));
        static::creating(function (CommercialDocument $d): void {
            $d->type = static::documentType();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class, 'status' => DocumentStatus::class, 'settlement_status' => SettlementStatus::class,
            'issuer_profile_snapshot' => 'array', 'counterparty_snapshot' => 'array', 'lines_snapshot' => 'array',
            'subtotal' => MoneyCast::class, 'discount_total' => MoneyCast::class, 'tax_total' => MoneyCast::class, 'total' => MoneyCast::class,
            'amount_received' => MoneyCast::class, 'amount_credited' => MoneyCast::class,
            'valid_until' => 'date', 'issue_date' => 'date', 'due_date' => 'date', 'issued_at' => 'datetime', 'approved_at' => 'datetime',
            'voided_at' => 'datetime', 'client_response_at' => 'datetime', 'effective_date' => 'date', 'revision_no' => 'integer', 'current_level' => 'integer',
        ];
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    /** Delegated approver of a pending item (APP role) — PermissionService also requires approve:assigned. */
    public function isAssignedTo(User $user): bool
    {
        return $this->status === DocumentStatus::PendingApproval;
    }

    /** @return HasMany<DocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class, 'commercial_document_id')->orderBy('line_no');
    }

    /** @return BelongsTo<DocumentTemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplateVersion::class);
    }

    public function outstanding(): Money
    {
        return $this->total->minus($this->amount_received)->minus($this->amount_credited);
    }

    public function displayNo(): string
    {
        if ($this->document_no === null) {
            return 'Draft #'.$this->id;
        }

        return $this->revision_no > 0 ? "{$this->document_no} rev {$this->revision_no}" : $this->document_no;
    }

    public function counterpartyName(): string
    {
        return (string) ($this->counterparty_snapshot['name'] ?? '—');
    }

    public function isEditable(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }
}
