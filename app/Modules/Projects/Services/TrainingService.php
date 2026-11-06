<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Documents\Contracts\GeneratedDocumentIssuer;
use App\Kernel\Identity\Models\User;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Projects\Exceptions\ProjectRejected;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\TrainingParticipant;
use App\Modules\Projects\Models\TrainingSession;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Training sessions: date, trainer, venue, participants (consent recorded), attendance, feedback (M02-06) and
 * certificate issuance through M03 (M03-06) for attendees who consented.
 *
 * @see SRS M02-06, M03-06, NFR-07
 */
final class TrainingService
{
    public function __construct(private readonly GeneratedDocumentIssuer $issuer, private readonly StaffDirectory $staff) {}

    /** @param  array<string, mixed>  $data */
    public function saveSession(Project $p, array $data, User $actor, ?TrainingSession $s = null): TrainingSession
    {
        $fields = ['course_title' => $data['course_title'], 'session_date' => $data['session_date'], 'trainer_staff_id' => $data['trainer_staff_id'] ?? null,
            'venue' => $data['venue'] ?? null, 'status' => $data['status'] ?? 'planned', 'feedback_comments' => $data['feedback_comments'] ?? null];
        if ($s === null) {
            return TrainingSession::query()->create($fields + ['project_id' => $p->id]);
        }
        $s->forceFill($fields)->save();

        return $s;
    }

    /** @param  list<array{id?: int|null, name: string, organisation?: string|null, email?: string|null, consent_given?: bool, attended?: bool, feedback_score?: string|null}>  $rows */
    public function saveParticipants(TrainingSession $s, array $rows, User $actor): TrainingSession
    {
        foreach ($rows as $r) {
            $fields = ['name' => $r['name'], 'organisation' => $r['organisation'] ?? null, 'email' => $r['email'] ?? null, 'consent_given' => (bool) ($r['consent_given'] ?? false),
                'attended' => (bool) ($r['attended'] ?? false), 'feedback_score' => isset($r['feedback_score']) && $r['feedback_score'] !== '' ? (string) $r['feedback_score'] : null];
            if (! empty($r['id'])) {
                $p = $s->participants()->whereKey($r['id'])->firstOrFail();
                if ($p->certificate_document_id !== null && $p->name !== $fields['name']) {
                    throw new ProjectRejected("A certificate was already issued to {$p->name}; the name can no longer change.");
                }
                $p->forceFill($fields)->save();
            } else {
                $s->participants()->create($fields);
            }
        }
        $scores = $s->participants()->whereNotNull('feedback_score')->pluck('feedback_score');
        $avg = $scores->isEmpty() ? null : (string) $scores->reduce(fn (BigDecimal $c, $v) => $c->plus((string) $v), BigDecimal::zero())->dividedBy($scores->count(), 2, RoundingMode::HALF_UP);
        $s->forceFill(['feedback_score' => $avg])->save();

        return $s->refresh();
    }

    /**
     * Issue certificates to attendees who gave consent and do not have one yet.
     *
     * @return int certificates issued
     */
    public function issueCertificates(TrainingSession $s, User $actor): int
    {
        if ($s->status !== 'completed') {
            throw new ProjectRejected('Mark the session as completed before issuing certificates.');
        }
        $trainer = $s->trainer_staff_id !== null ? ($this->staff->snapshot($s->trainer_staff_id)['name'] ?? null) : null;
        $count = 0;
        foreach ($s->participants()->where('attended', true)->where('consent_given', true)->whereNull('certificate_document_id')->get() as $p) {
            /** @var TrainingParticipant $p */
            $cert = $this->issuer->issueCertificate([
                'participant_name' => $p->name, 'organisation' => $p->organisation, 'course_title' => $s->course_title, 'course_date' => $s->session_date->toDateString(),
                'project_id' => $s->project_id, 'source_id' => $p->id, 'trainer' => $trainer,
            ], $actor);
            $p->forceFill(['certificate_document_id' => $cert['id']])->save();
            $count++;
        }
        Audit::log('issue_certificates', $s, null, null, ['issued' => $count], null, $actor);

        return $count;
    }
}
