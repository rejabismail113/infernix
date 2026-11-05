<?php

declare(strict_types=1);

namespace App\Kernel\Audit;

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Models\User;
use App\Kernel\Secrets\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The one way to write audit rows. Payloads are redacted (PII keys) and scrubbed (secret sentinels).
 *
 * @see SRS G-06, M12-04, NFR-03
 */
final class AuditLogger
{
    public function __construct(private readonly SecretRedactor $secrets) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  list<string>  $extraRedactKeys
     */
    public function log(
        string $action,
        string|Model $entity,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?User $actor = null,
        array $extraRedactKeys = [],
    ): AuditEvent {
        if ($entity instanceof Model) {
            $entityId ??= (int) $entity->getKey();
            $entityType = $entity::class;
        } else {
            $entityType = $entity;
        }

        $redactor = new Redactor($extraRedactKeys);
        [$beforeClean, $r1] = $redactor->redact($before);
        [$afterClean, $r2] = $redactor->redact($after);

        $actor ??= $this->currentUser();
        $request = app()->bound('request') ? app(Request::class) : null;

        return AuditEvent::query()->create([
            'company_id' => $actor?->company_id,
            'actor_id' => $actor?->id,
            'actor_role' => $actor ? implode(',', array_map(fn ($r) => $r->value, $actor->roleCodes())) : 'system',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $this->secrets->scrubArray($beforeClean),
            'after' => $this->secrets->scrubArray($afterClean),
            'redactions' => array_values(array_unique([...$r1, ...$r2])),
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'request_id' => $this->requestId($request),
            'reason' => $reason !== null ? $this->secrets->scrub($reason) : null,
            'occurred_at' => now(),
        ]);
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function requestId(?Request $request): string
    {
        if ($request !== null && $request->attributes->has('request_id')) {
            return (string) $request->attributes->get('request_id');
        }
        $id = (string) Str::uuid();
        $request?->attributes->set('request_id', $id);

        return $id;
    }
}
