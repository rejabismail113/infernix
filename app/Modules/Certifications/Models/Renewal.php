<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One renewal cycle of a certification or membership. Its cost is LINKED to the finance record that paid it
 * (finance_links entity renewal, purpose renewal) — never a second expense (M09-04).
 *
 * @see SRS M09-02, M09-04
 *
 * @property int $id
 * @property string $renewable_type
 * @property int $renewable_id
 * @property Carbon $due_date
 * @property string $status
 * @property Carbon|null $renewed_on
 * @property Carbon|null $new_expiry_date
 * @property string|null $note
 * @property int|null $decided_by
 */
final class Renewal extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_date' => 'date', 'renewed_on' => 'date', 'new_expiry_date' => 'date', 'renewable_id' => 'integer'];
    }

    public function renewable(): Certification|Membership|null
    {
        return $this->renewable_type === 'certification' ? Certification::query()->find($this->renewable_id) : Membership::query()->find($this->renewable_id);
    }
}
