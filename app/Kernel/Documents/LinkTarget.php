<?php

declare(strict_types=1);

namespace App\Kernel\Documents;

/** A business record a document is attached to (validated against DocumentLinkRegistry). */
final class LinkTarget
{
    public function __construct(public readonly string $entityType, public readonly int $entityId, public readonly string $linkType) {}
}
