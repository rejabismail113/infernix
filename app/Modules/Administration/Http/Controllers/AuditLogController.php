<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Reports\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Audit log UI (OWN/AUD) with filters and an audited CSV export.
 *
 * @see SRS G-06, M12-04
 */
final class AuditLogController
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditEvent::class);
        $filters = $this->filters($request);
        $events = $this->query($filters)->with('actor:id,name')->paginate(50)->withQueryString()
            ->through(fn (AuditEvent $e): array => $this->present($e));

        return Inertia::render('Admin/AuditLog/Index', [
            'events' => $events,
            'filters' => $filters,
            'actions' => AuditEvent::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'can' => ['export' => $request->user()?->can('export', AuditEvent::class) ?? false],
        ]);
    }

    public function export(Request $request, CsvExport $csv): StreamedResponse
    {
        $this->authorize('export', AuditEvent::class);
        $filters = $this->filters($request);
        $rows = $this->query($filters)->with('actor:id,name')->lazy(500)->map(fn (AuditEvent $e): array => [
            $e->id, $e->occurred_at->toIso8601String(), $e->actor?->name, $e->actor_role, $e->action, $e->entity_type, $e->entity_id,
            json_encode($e->before), json_encode($e->after), implode('|', $e->redactions ?? []), $e->ip, $e->request_id, $e->reason,
        ]);

        return $csv->stream('audit_log', ['id', 'occurred_at', 'actor', 'actor_role', 'action', 'entity_type', 'entity_id', 'before', 'after', 'redactions', 'ip', 'request_id', 'reason'], $rows, $filters);
    }

    /** @return array<string, string|null> */
    private function filters(Request $request): array
    {
        /** @var array<string, string|null> $f */
        $f = $request->validate([
            'actor_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:48'],
            'entity_type' => ['nullable', 'string', 'max:96'],
            'entity_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return $f;
    }

    /**
     * @param  array<string, string|null>  $f
     * @return Builder<AuditEvent>
     */
    private function query(array $f): Builder
    {
        return AuditEvent::query()->orderByDesc('id')
            ->when($f['actor_id'] ?? null, fn (Builder $q, $v) => $q->where('actor_id', $v))
            ->when($f['action'] ?? null, fn (Builder $q, $v) => $q->where('action', $v))
            ->when($f['entity_type'] ?? null, fn (Builder $q, $v) => $q->where('entity_type', 'like', '%'.$v.'%'))
            ->when($f['entity_id'] ?? null, fn (Builder $q, $v) => $q->where('entity_id', $v))
            ->when($f['from'] ?? null, fn (Builder $q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($f['to'] ?? null, fn (Builder $q, $v) => $q->where('occurred_at', '<', Carbon::parse($v)->addDay()));
    }

    /** @return array<string, mixed> */
    private function present(AuditEvent $e): array
    {
        return [
            'id' => $e->id, 'occurred_at' => $e->occurred_at->toIso8601String(), 'actor' => $e->actor?->name, 'actor_role' => $e->actor_role,
            'action' => $e->action, 'entity_type' => class_basename($e->entity_type), 'entity_id' => $e->entity_id,
            'before' => $e->before, 'after' => $e->after, 'redactions' => $e->redactions, 'ip' => $e->ip, 'request_id' => $e->request_id, 'reason' => $e->reason,
        ];
    }
}
