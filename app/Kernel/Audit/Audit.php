<?php

declare(strict_types=1);

namespace App\Kernel\Audit;

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Static helpers: Audit::log('export', …), Audit::reveal(…), Audit::login(…).
 *
 * @see SRS G-06, M12-04
 */
final class Audit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function log(string $action, string|Model $entity, ?int $entityId = null, ?array $before = null, ?array $after = null, ?string $reason = null, ?User $actor = null): AuditEvent
    {
        return app(AuditLogger::class)->log($action, $entity, $entityId, $before, $after, $reason, $actor);
    }

    /** @param  array<string, mixed>  $context */
    public static function export(string $report, array $context = [], ?User $actor = null): AuditEvent
    {
        return self::log('export', $report, null, null, $context, null, $actor);
    }

    public static function reveal(Model $entity, string $field, ?string $reason = null): AuditEvent
    {
        return self::log('reveal', $entity, null, null, ['field' => $field], $reason);
    }
}
