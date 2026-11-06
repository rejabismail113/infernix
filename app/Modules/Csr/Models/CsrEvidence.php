<?php

declare(strict_types=1);

namespace App\Modules\Csr\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Evidence photo metadata: stored as a RESTRICTED document; identifiable people may only be marked publishable
 * once consent is recorded (DB CHECK, M08-04 / PDPA).
 *
 * @see SRS M08-04
 *
 * @property int $id
 * @property int $programme_id
 * @property int $document_id
 * @property bool $people_identifiable
 * @property bool $consent_obtained
 * @property string|null $consent_note
 * @property bool $publishable
 * @property int $created_by
 */
final class CsrEvidence extends Model
{
    use Auditable;

    protected $table = 'csr_evidence';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['people_identifiable' => 'boolean', 'consent_obtained' => 'boolean', 'publishable' => 'boolean'];
    }
}
