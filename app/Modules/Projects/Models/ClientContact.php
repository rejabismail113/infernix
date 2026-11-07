<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contact person at a client (several per client; one primary, mirrored to clients.contact_person for documents).
 *
 * @see SRS M02-01
 *
 * @property int $id
 * @property int $client_id
 * @property string $name
 * @property string|null $position
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 * @property int $sort_order
 */
final class ClientContact extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['client_id', 'name', 'position', 'email', 'phone', 'is_primary', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
