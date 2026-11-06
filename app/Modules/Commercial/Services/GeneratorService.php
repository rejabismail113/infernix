<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\Contracts\GeneratedDocumentIssuer;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Events\DocumentIssued;
use App\Modules\Commercial\Exceptions\CommercialRejected;
use App\Modules\Commercial\Models\Certificate;
use App\Modules\Commercial\Models\ClientPurchaseOrder;
use App\Modules\Commercial\Models\CommercialDocument;
use App\Modules\Commercial\Models\Quotation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Generated documents (M03-06) — staff memo, appointment letter, letter, training certificate — and incoming
 * client POs / LOAs (M03-05). They use versioned templates and the same issue-time snapshot + DB immutability as
 * every other issued document; numbers are gap-free (MEMO-, APL-, LTR-, CERT-, CPO-).
 *
 * @see SRS M03-05, M03-06, M03-07, M03-08, M04 (memo inbox)
 */
final class GeneratorService implements GeneratedDocumentIssuer
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly StaffDirectory $staff,
        private readonly ClientDirectory $clients,
        private readonly IssuerProfile $issuer,
        private readonly TemplateRenderer $templates,
        private readonly DocumentPdfService $pdfs,
        private readonly DocumentStore $documents,
    ) {}

    /**
     * @param  array{staff_profile_id?: int|null, recipient_name?: string|null, recipient_address?: string|null, client_id?: int|null, subject: string, body?: string|null, effective_date?: string|null, project_id?: int|null}  $data
     */
    public function createDraft(DocumentType $type, array $data, User $actor): CommercialDocument
    {
        if (! in_array($type, [DocumentType::Memo, DocumentType::Letter, DocumentType::AppointmentLetter], true)) {
            throw new CommercialRejected('This document type is not drafted here.');
        }
        $class = DocumentRegistry::modelFor($type);
        /** @var CommercialDocument $doc */
        $doc = new $class;
        $doc->forceFill($this->fields($type, $data) + ['company_id' => $actor->company_id, 'status' => DocumentStatus::Draft, 'created_by' => $actor->id])->save();
        Audit::log('create', $doc, null, null, ['type' => $type->value, 'subject' => $doc->subject], null, $actor);

        return $doc;
    }

    /** @param  array{staff_profile_id?: int|null, recipient_name?: string|null, recipient_address?: string|null, client_id?: int|null, subject: string, body?: string|null, effective_date?: string|null, project_id?: int|null}  $data */
    public function updateDraft(CommercialDocument $doc, array $data, User $actor): CommercialDocument
    {
        if ($doc->status !== DocumentStatus::Draft) {
            throw new CommercialRejected('Only drafts can be edited.');
        }
        $doc->forceFill($this->fields($doc->type, $data))->save();

        return $doc;
    }

    /** Default appointment wording from the staff profile and the rate agreement in force. */
    public function appointmentBody(int $staffProfileId, string $startDate): string
    {
        $s = $this->staff->snapshot($staffProfileId) ?? throw new CommercialRejected('Unknown staff member.');
        $e = $this->staff->engagementOn($staffProfileId, $startDate);
        $unit = ['hourly' => 'hour', 'daily' => 'day', 'per_session' => 'session', 'fixed' => 'engagement'][$e['rate_type'] ?? 'fixed'] ?? 'engagement';
        $rate = $e !== null ? 'The agreed rate is RM '.Money::format($e['rate_amount'])." per {$unit} (rate agreement version {$e['version']})." : 'The rate is set out in your agreement.';

        return "Dear {$s['legal_name']},\n\nWe are pleased to appoint you as ".($s['position'] ?: 'a member of our team').' on a '.str_replace('_', '-', $s['engagement_type'])
            .' basis with effect from '.Carbon::parse($startDate)->format('j F Y').".\n\n{$rate}\n\nThis appointment is subject to the terms of your agreement and the company's policies. [PLACEHOLDER — legal review]\n\nPlease sign and return a copy to confirm your acceptance.";
    }

    /** Issue a memo / letter / appointment letter directly (no approval): number + snapshot + PDF; staff memo inbox via event. */
    public function issue(CommercialDocument $doc, User $actor): CommercialDocument
    {
        $issued = DB::transaction(function () use ($doc, $actor): CommercialDocument {
            /** @var CommercialDocument $d */
            $d = $doc::query()->lockForUpdate()->findOrFail($doc->id);
            DocumentStatus::generatedTransitions()->assert($d->type->label(), $d->status, DocumentStatus::Issued);
            if (trim((string) $d->body) === '' || trim((string) $d->subject) === '') {
                throw new CommercialRejected('A subject and body are required.');
            }
            $counterparty = $d->counterparty_type === 'staff' ? $this->staffSnapshot((int) $d->counterparty_id) : $d->counterparty_snapshot;
            $date = today();
            $d->forceFill([
                'document_no' => $this->numbering->allocate((int) $d->company_id, $d->type->sequence(), (int) $date->format('Y'))['formatted'],
                'issue_date' => $date->toDateString(), 'template_version_id' => $this->templates->currentVersion($d->type)->id,
                'issuer_profile_snapshot' => $this->issuer->snapshot(), 'counterparty_snapshot' => $counterparty,
                'status' => DocumentStatus::Issued, 'issued_by' => $actor->id, 'issued_at' => now(),
            ])->save();
            Audit::log('issue', $d, null, ['status' => 'draft'], ['status' => 'issued', 'document_no' => $d->document_no], null, $actor);

            return $d;
        });
        $pdf = $this->pdfs->storeIssued($issued, $actor);
        if ($pdf !== null && $issued->counterparty_type === 'staff' && $issued->counterparty_id !== null) {
            // The recipient sees it through their own staff profile (record scope "own").
            $this->documents->link($pdf, new LinkTarget('staff_profile', $issued->counterparty_id, $issued->type === DocumentType::AppointmentLetter ? 'appointment' : 'memo'), $actor);
        }
        Event::dispatch(new DocumentIssued($issued->id, $issued->type->value, $issued->counterparty_type, $issued->counterparty_id, $issued->subject));

        return $issued->refresh();
    }

    /**
     * Record an incoming client PO / LOA (M03-05): our CPO number, the client's own reference, linked to the
     * quotation and/or project. The client's scan is attached via the Document Centre (link type client_po).
     *
     * @param  array{client_id: int, external_ref: string, po_date: string, amount: string, quotation_id?: int|null, project_id?: int|null, title?: string|null}  $data
     */
    public function recordClientPo(array $data, User $actor): ClientPurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor): ClientPurchaseOrder {
            $client = $this->clients->snapshot($data['client_id']) ?? throw new CommercialRejected('Unknown client.');
            if (! empty($data['quotation_id'])) {
                $q = Quotation::query()->findOrFail($data['quotation_id']);
                if ($q->client_id !== $client['id']) {
                    throw new CommercialRejected('The quotation belongs to another client.');
                }
            }
            $dup = ClientPurchaseOrder::query()->where('client_id', $client['id'])->whereRaw('lower(external_ref) = ?', [mb_strtolower(trim($data['external_ref']))])->value('document_no');
            if ($dup !== null) {
                throw new CommercialRejected("This client PO is already recorded as {$dup}.");
            }
            $date = Carbon::parse($data['po_date']);
            $amount = Money::myr($data['amount']);
            $po = new ClientPurchaseOrder;
            $po->forceFill([
                'company_id' => $actor->company_id, 'document_no' => $this->numbering->allocate((int) $actor->company_id, 'CLIENT_PO', (int) $date->format('Y'))['formatted'],
                'status' => DocumentStatus::Issued, 'counterparty_type' => 'client', 'counterparty_id' => $client['id'], 'client_id' => $client['id'], 'counterparty_snapshot' => $client,
                'external_ref' => trim($data['external_ref']), 'effective_date' => $date->toDateString(), 'issue_date' => $date->toDateString(), 'title' => $data['title'] ?? null,
                'related_document_id' => $data['quotation_id'] ?? null, 'project_id' => $data['project_id'] ?? null,
                'subtotal' => $amount, 'tax_total' => Money::zero(), 'total' => $amount, 'issuer_profile_snapshot' => $this->issuer->snapshot(),
                'source_type' => ! empty($data['quotation_id']) ? 'quotation' : 'manual', 'source_id' => $data['quotation_id'] ?? null,
                'created_by' => $actor->id, 'issued_by' => $actor->id, 'issued_at' => now(),
            ])->save();
            Audit::log('record', $po, null, null, ['document_no' => $po->document_no, 'external_ref' => $po->external_ref, 'amount' => Money::toDb($amount)], null, $actor);

            return $po;
        });
    }

    public function issueCertificate(array $data, User $actor): array
    {
        $cert = DB::transaction(function () use ($data, $actor): Certificate {
            $existing = Certificate::query()->where('source_type', 'training_participant')->where('source_id', $data['source_id'])->whereIn('status', ['issued'])->first();
            if ($existing !== null) {
                return $existing;
            }
            $date = today();
            $c = new Certificate;
            $c->forceFill([
                'company_id' => $actor->company_id, 'document_no' => $this->numbering->allocate((int) $actor->company_id, 'CERTIFICATE', (int) $date->format('Y'))['formatted'],
                'status' => DocumentStatus::Issued, 'counterparty_type' => 'participant', 'counterparty_id' => $data['source_id'],
                'counterparty_snapshot' => ['name' => $data['participant_name'], 'organisation' => $data['organisation']],
                'subject' => $data['course_title'], 'effective_date' => $data['course_date'], 'project_id' => $data['project_id'], 'issue_date' => $date->toDateString(),
                'verification_token' => Str::lower(Str::random(40)), 'template_version_id' => $this->templates->currentVersion(DocumentType::Certificate)->id,
                'issuer_profile_snapshot' => $this->issuer->snapshot(), 'lines_snapshot' => ['lines' => [], 'extra' => ['trainer' => $data['trainer']]],
                'source_type' => 'training_participant', 'source_id' => $data['source_id'], 'created_by' => $actor->id, 'issued_by' => $actor->id, 'issued_at' => now(),
            ])->save();
            Audit::log('issue', $c, null, null, ['document_no' => $c->document_no, 'course' => $data['course_title']], null, $actor);

            return $c;
        });
        $pdf = $this->pdfs->storeIssued($cert, $actor);
        Event::dispatch(new DocumentIssued($cert->id, 'certificate', 'participant', (int) $data['source_id'], $cert->subject));

        return ['id' => $cert->id, 'number' => (string) $cert->document_no, 'pdf_document_id' => $pdf, 'verify_url' => route('verify.certificate', $cert->verification_token)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(DocumentType $type, array $data): array
    {
        if (in_array($type, [DocumentType::Memo, DocumentType::AppointmentLetter], true)) {
            $staff = $this->staffSnapshot((int) ($data['staff_profile_id'] ?? 0));
            $party = ['counterparty_type' => 'staff', 'counterparty_id' => $staff['id'], 'counterparty_snapshot' => $staff, 'client_id' => null];
        } elseif (! empty($data['client_id'])) {
            $client = $this->clients->snapshot((int) $data['client_id']) ?? throw new CommercialRejected('Unknown client.');
            $party = ['counterparty_type' => 'client', 'counterparty_id' => $client['id'], 'counterparty_snapshot' => $client, 'client_id' => $client['id']];
        } else {
            $name = trim((string) ($data['recipient_name'] ?? ''));
            if ($name === '') {
                throw new CommercialRejected('Name the recipient.');
            }
            $party = ['counterparty_type' => 'payee', 'counterparty_id' => null, 'counterparty_snapshot' => ['name' => $name, 'address' => $data['recipient_address'] ?? null], 'client_id' => null];
        }
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '' && $type === DocumentType::AppointmentLetter) {
            $body = $this->appointmentBody((int) $party['counterparty_id'], (string) ($data['effective_date'] ?? today()->toDateString()));
        }

        return $party + ['subject' => trim((string) $data['subject']), 'title' => trim((string) $data['subject']), 'body' => $body, 'effective_date' => $data['effective_date'] ?? null, 'project_id' => $data['project_id'] ?? null];
    }

    /** @return array<string, mixed> */
    private function staffSnapshot(int $id): array
    {
        $s = $this->staff->snapshot($id) ?? throw new CommercialRejected('Choose the staff member.');

        return ['name' => $s['legal_name'], 'staff_no' => $s['staff_no'], 'position' => $s['position'], 'email' => $s['email'], 'id' => $s['id']];
    }
}
