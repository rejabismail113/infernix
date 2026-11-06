<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participant (PII: email encrypted; consent recorded before any certificate / follow-up, PDPA).
 *
 * @see SRS M02-06, M03-06, NFR-07
 *
 * @property int $id
 * @property int $training_session_id
 * @property string $name
 * @property string|null $organisation
 * @property string|null $email
 * @property bool $consent_given
 * @property bool $attended
 * @property string|null $feedback_score
 * @property int|null $certificate_document_id
 * @property-read TrainingSession $session
 */
final class TrainingParticipant extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['training_session_id', 'name', 'organisation', 'email', 'consent_given', 'attended', 'feedback_score'];

    /** @var list<string> */
    protected $hidden = ['email'];

    /** @var list<string> */
    protected array $auditRedact = ['email'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['email' => 'encrypted', 'consent_given' => 'boolean', 'attended' => 'boolean'];
    }

    /** @return BelongsTo<TrainingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }
}
