<?php

declare(strict_types=1);

namespace App\Modules\Administration\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Administration\Models\Dictionary;

/** @see SRS G-09, M12-05 */
final class DictionaryPolicy extends ResourcePolicy
{
    protected string $model = Dictionary::class;
}
