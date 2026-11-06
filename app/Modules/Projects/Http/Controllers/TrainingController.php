<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Models\User;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\TrainingParticipant;
use App\Modules\Projects\Models\TrainingSession;
use App\Modules\Projects\Services\TrainingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Training sessions, participants, attendance, feedback and certificates (M02-06, M03-06). Access follows the project. */
final class TrainingController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly TrainingService $service, private readonly StaffDirectory $staff) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $this->validated($request);

        return $this->attempt(function () use ($project, $data, $request): RedirectResponse {
            $s = $this->service->saveSession($project, $data, $this->user($request));

            return redirect()->route('projects.sessions.show', $s->id)->with('success', __('Session added.'));
        }, '');
    }

    public function show(Request $request, TrainingSession $session): Response
    {
        $this->authorize('view', $session->project);
        /** @var User $user */
        $user = $request->user();
        $edit = $user->can('update', $session->project);
        $certs = DB::table('commercial_documents')->whereIn('id', $session->participants()->whereNotNull('certificate_document_id')->pluck('certificate_document_id'))
            ->get(['id', 'document_no', 'status', 'pdf_document_id', 'verification_token'])->keyBy('id');

        return Inertia::render('Projects/Training/Show', [
            'session' => ['id' => $session->id, 'course_title' => $session->course_title, 'session_date' => $session->session_date->toDateString(), 'venue' => $session->venue, 'status' => $session->status,
                'trainer_staff_id' => $session->trainer_staff_id, 'trainer' => $session->trainer_staff_id ? ($this->staff->snapshot($session->trainer_staff_id)['name'] ?? null) : null,
                'feedback_score' => $session->feedback_score, 'feedback_comments' => $session->feedback_comments],
            'project' => ['id' => $session->project->id, 'code' => $session->project->code, 'name' => $session->project->name],
            // Participant emails (PII) are only sent to users who may edit the project.
            'participants' => $session->participants()->get()->map(function (TrainingParticipant $p) use ($edit, $certs): array {
                $c = $p->certificate_document_id !== null ? $certs[$p->certificate_document_id] ?? null : null;

                return ['id' => $p->id, 'name' => $p->name, 'organisation' => $p->organisation, 'email' => $edit ? $p->email : null, 'consent_given' => $p->consent_given, 'attended' => $p->attended,
                    'feedback_score' => $p->feedback_score, 'certificate' => $c === null ? null : ['id' => $c->id, 'number' => $c->document_no, 'status' => $c->status, 'pdf_document_id' => $c->pdf_document_id,
                        'verify_url' => $c->verification_token ? route('verify.certificate', $c->verification_token) : null]];
            }),
            'trainers' => $edit ? $this->staff->options($user) : [],
            'can' => ['edit' => $edit],
        ]);
    }

    public function update(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('update', $session->project);

        return $this->attempt(fn () => $this->service->saveSession($session->project, $this->validated($request), $this->user($request), $session), __('Session updated.'));
    }

    public function participants(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('update', $session->project);
        $data = $request->validate(['participants' => ['required', 'array', 'max:500'], 'participants.*.id' => ['nullable', 'integer'], 'participants.*.name' => ['required', 'string', 'max:190'],
            'participants.*.organisation' => ['nullable', 'string', 'max:190'], 'participants.*.email' => ['nullable', 'email', 'max:190'], 'participants.*.consent_given' => ['boolean'],
            'participants.*.attended' => ['boolean'], 'participants.*.feedback_score' => ['nullable', 'numeric', 'min:0', 'max:5']]);

        return $this->attempt(fn () => $this->service->saveParticipants($session, array_values($data['participants']), $this->user($request)), __('Participants saved.'));
    }

    public function certificates(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('update', $session->project);

        return $this->attempt(function () use ($session, $request): RedirectResponse {
            $n = $this->service->issueCertificates($session, $this->user($request));

            return back()->with('success', trans_choice(':n certificate issued.|:n certificates issued.', $n, ['n' => $n]));
        }, '');
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate(['course_title' => ['required', 'string', 'max:190'], 'session_date' => ['required', 'date'], 'trainer_staff_id' => ['nullable', 'integer', 'exists:staff_profiles,id'],
            'venue' => ['nullable', 'string', 'max:190'], 'status' => ['nullable', 'in:planned,completed,cancelled'], 'feedback_comments' => ['nullable', 'string', 'max:4000']]);
    }
}
