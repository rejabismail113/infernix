<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Models\User;
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

    /** Keys inside before/after JSON that hold a user id (mirrored in resources/js/lib/auditDiff.ts). */
    public const PERSON_KEY = '/^(user_id|owner_id|actor_id|approver_id|delegate_id|delegator_id|pm_user_id|staff_user_id|uploaded_by|linked_by|shared_by|triggered_by|[a-z_]+_by)$/';

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditEvent::class);
        $filters = $this->filters($request);
        $events = $this->query($filters)->with('actor:id,name')->paginate($this->perPage($request))->withQueryString()
            ->through(fn (AuditEvent $e): array => $this->present($e));

        return Inertia::render('Admin/AuditLog/Index', [
            'events' => $events,
            // Names for user ids referenced inside before/after (approved_by, posted_by …) on THIS page only, so the
            // log reads "Approved by: Hakim" instead of a number. Viewers of the audit log may already see actors.
            'people' => $this->peopleOn($events->items()),
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
            'sort' => ['nullable', 'in:occurred_at,action,entity_type,actor_id'],
            'dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return $f;
    }

    /**
     * @param  array<string, string|null>  $f
     * @return Builder<AuditEvent>
     */
    private function query(array $f): Builder
    {
        $sort = $f['sort'] ?? 'occurred_at';
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return AuditEvent::query()->orderBy($sort, $dir)->orderBy('id', $dir)
            ->when($f['actor_id'] ?? null, fn (Builder $q, $v) => $q->where('actor_id', $v))
            ->when($f['action'] ?? null, fn (Builder $q, $v) => $q->where('action', $v))
            ->when($f['entity_type'] ?? null, fn (Builder $q, $v) => $q->where('entity_type', 'like', '%'.$v.'%'))
            ->when($f['entity_id'] ?? null, fn (Builder $q, $v) => $q->where('entity_id', $v))
            ->when($f['from'] ?? null, fn (Builder $q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($f['to'] ?? null, fn (Builder $q, $v) => $q->where('occurred_at', '<', Carbon::parse($v)->addDay()));
    }

    private function perPage(Request $request): int
    {
        $n = $request->integer('per_page', 25);

        return in_array($n, [10, 25, 50, 100], true) ? $n : 25;
    }

    /**
     * @param  array<int, mixed>  $rows  presented events
     * @return array<int, string> user id => name
     */
    private function peopleOn(array $rows): array
    {
        $ids = [];
        $walk = function (mixed $data) use (&$ids): void {
            if (! is_array($data)) {
                return;
            }
            foreach ($data as $key => $value) {
                if (is_string($key) && preg_match(self::PERSON_KEY, $key) === 1 && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
                    $ids[(int) $value] = true;
                }
            }
        };
        foreach ($rows as $row) {
            if (is_array($row)) {
                $walk($row['before'] ?? null);
                $walk($row['after'] ?? null);
            }
        }
        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $names */
        $names = User::query()->whereIn('id', array_keys($ids))->pluck('name', 'id')->all();

        return $names;
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
