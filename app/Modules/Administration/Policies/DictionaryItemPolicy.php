<?php

declare(strict_types=1);

namespace App\Modules\Administration\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Administration\Models\DictionaryItem;

/** @see SRS G-09, M12-05 */
final class DictionaryItemPolicy extends ResourcePolicy
{
    protected string $model = DictionaryItem::class;
}
