<?php

declare(strict_types=1);

namespace App\Kernel\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Records create/update/delete of a domain model into audit_events with redacted before/after.
 * Models may list extra sensitive attributes in `protected array $auditRedact = [...]`.
 *
 * @see SRS G-06, NFR-03
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            $model->writeAudit('create', null, $model->getAttributes());
        });
        static::updated(function (self $model): void {
            $changes = $model->getChanges();
            if ($changes === []) {
                return;
            }
            $before = array_intersect_key($model->getOriginal(), $changes);
            $model->writeAudit('update', $before, $changes);
        });
        static::deleted(function (self $model): void {
            $model->writeAudit('delete', $model->getOriginal(), null);
        });
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function writeAudit(string $action, ?array $before, ?array $after): void
    {
        /** @var list<string> $ignore */
        $ignore = config('audit.ignore_keys', []);
        $strip = fn (?array $a): ?array => $a === null ? null : array_diff_key($a, array_flip($ignore));
        /** @var list<string> $extra */
        $extra = property_exists($this, 'auditRedact') ? $this->auditRedact : [];

        app(AuditLogger::class)->log($action, $this, null, $strip($before), $strip($after), null, null, $extra);
    }
}
