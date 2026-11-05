<?php

declare(strict_types=1);

namespace App\Kernel\Notifications;

use Closure;

/**
 * Modules register a source of items awaiting approval; the scheduled job reminds the eligible approvers.
 * A source returns list<array{key: string, title: string, url: string, roles: list<string>, preparer_id: int|null}>.
 *
 * @see SRS G-05, M12-03
 */
final class PendingApprovalRegistry
{
    /** @var array<string, Closure(): list<array{key: string, title: string, url: string, roles: list<string>, preparer_id: int|null}>> */
    private array $sources = [];

    /** @param  Closure(): list<array{key: string, title: string, url: string, roles: list<string>, preparer_id: int|null}>  $source */
    public function register(string $name, Closure $source): void
    {
        $this->sources[$name] = $source;
    }

    /** @return list<array{key: string, title: string, url: string, roles: list<string>, preparer_id: int|null}> */
    public function items(): array
    {
        $items = [];
        foreach ($this->sources as $source) {
            array_push($items, ...$source());
        }

        return $items;
    }
}
