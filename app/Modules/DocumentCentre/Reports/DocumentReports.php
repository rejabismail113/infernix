<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Reports;

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Models\RequiredEvidenceRule;
use App\Modules\DocumentCentre\Services\DocumentAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Document inventory, recent changes and missing required evidence — all row-permission filtered.
 *
 * @see SRS M06 outputs, M06-04, M11-05
 */
final class DocumentReports
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentLinkRegistry $registry,
        private readonly PermissionService $permissions,
    ) {}

    /** @return list<array<string, mixed>> */
    public function inventory(User $user, bool $includeArchived = true): array
    {
        $q = $this->access->scopeVisible(Document::query(), $user)
            ->with(['currentVersion', 'owner:id,name', 'retentionRule:id,code'])
            ->withCount(['versions', 'links'])
            ->orderBy('documents.id');
        if (! $includeArchived) {
            $q->whereNull('documents.archived_at');
        }

        return $q->get()->map(fn (Document $d): array => [
            'id' => $d->id,
            'title' => $d->title,
            'classification' => $d->classification->value,
            'owner' => $d->owner->name,
            'current_version' => $d->current_version_no,
            'versions' => $d->versions_count,
            'links' => $d->links_count,
            'file_name' => $d->currentVersion?->file_name,
            'mime_type' => $d->currentVersion?->mime_type,
            'size_bytes' => $d->currentVersion?->size_bytes,
            'content_hash' => $d->currentVersion?->content_hash,
            'retention' => $d->retentionRule?->code,
            'retention_until' => $d->retention_until?->toDateString(),
            'legal_hold' => $d->legal_hold,
            'frozen' => $d->frozen,
            'archived_at' => $d->archived_at?->toIso8601String(),
            'uploaded_at' => $d->uploaded_at->toIso8601String(),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    public function recentChanges(User $user, int $days = 30): array
    {
        $visible = $this->access->scopeVisible(Document::query(), $user)->select('documents.id');
        $seesDeleted = $this->permissions->can($user, PermissionAction::Delete, 'documents');

        return AuditEvent::query()
            ->with('actor:id,name')
            ->where('entity_type', Document::class)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->where(function ($q) use ($visible, $seesDeleted): void {
                $q->whereIn('entity_id', $visible);
                if ($seesDeleted) {
                    $q->orWhere('action', 'hard_delete');
                }
            })
            ->orderByDesc('id')->limit(500)->get()
            ->map(fn (AuditEvent $e): array => [
                'id' => $e->id, 'occurred_at' => $e->occurred_at->toIso8601String(), 'document_id' => $e->entity_id,
                'action' => $e->action, 'actor' => $e->actor?->name, 'reason' => $e->reason, 'after' => $e->after,
            ])->all();
    }

    /**
     * For each active rule: records subject to it (that the user may view) lacking a linked, non-deleted
     * document with the required link type. Rules for modules not installed yet are reported as pending.
     *
     * @return list<array<string, mixed>>
     */
    public function missingEvidence(User $user): array
    {
        $rows = [];
        foreach (RequiredEvidenceRule::query()->where('active', true)->orderBy('entity_type')->get() as $rule) {
            if (! $this->registry->has($rule->entity_type) || $this->registry->get($rule->entity_type)->evidenceSubjects === null) {
                $rows[] = ['rule_id' => $rule->id, 'entity_type' => $rule->entity_type, 'link_type' => $rule->link_type, 'description' => $rule->description, 'status' => 'pending_module', 'missing' => []];

                continue;
            }
            $entity = $this->registry->get($rule->entity_type);
            /** @var array<int, string> $subjects */
            $subjects = ($entity->evidenceSubjects)($rule->condition);
            $accessible = $this->registry->accessibleIds($rule->entity_type, $user);
            if ($accessible !== '*') {
                $subjects = array_intersect_key($subjects, array_flip($accessible));
            }
            $have = $subjects === [] ? [] : DB::table('entity_document_links')
                ->join('documents', 'documents.id', '=', 'entity_document_links.document_id')
                ->whereNull('documents.hard_deleted_at')
                ->where('entity_document_links.entity_type', $rule->entity_type)
                ->where('entity_document_links.link_type', $rule->link_type)
                ->whereIn('entity_document_links.entity_id', array_keys($subjects))
                ->pluck('entity_document_links.entity_id')->map(fn ($v): int => (int) $v)->all();
            $missing = [];
            foreach (array_diff_key($subjects, array_flip($have)) as $id => $label) {
                $missing[] = ['entity_id' => $id, 'label' => $label];
            }
            $rows[] = ['rule_id' => $rule->id, 'entity_type' => $rule->entity_type, 'link_type' => $rule->link_type, 'description' => $rule->description, 'status' => $missing === [] ? 'complete' : 'missing', 'missing' => $missing];
        }

        return $rows;
    }

    public static function since(int $days): Carbon
    {
        return now()->subDays($days);
    }
}
