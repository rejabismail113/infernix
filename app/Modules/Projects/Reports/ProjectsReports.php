<?php

declare(strict_types=1);

namespace App\Modules\Projects\Reports;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Money\Money;
use App\Modules\Projects\Models\Lead;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\TrainingSession;
use Illuminate\Support\Carbon;

/**
 * M02 outputs: project pipeline, overdue milestones, training register — rows filtered by the user's record
 * scope (PMs: own leads, assigned projects).
 *
 * @see SRS M02 (Output / reporting), M02-07, M11-05
 */
final class ProjectsReports
{
    public function __construct(private readonly PermissionService $permissions) {}

    /** @return array<string, mixed> */
    public function pipeline(?User $user): array
    {
        $scope = $user ? $this->permissions->scopeFor($user, 'leads', PermissionAction::View) : RecordScope::All;
        $leads = Lead::query()->with('client')->when($scope !== RecordScope::All, fn ($q) => $q->where('owner_user_id', $user->id ?? 0))->orderBy('expected_close')->get();
        $totals = [];
        foreach (Lead::STAGES as $stage) {
            $totals[$stage] = (string) $leads->where('stage', $stage)->reduce(fn ($c, Lead $l) => $c->plus($l->estimated_value), Money::zero())->getAmount();
        }

        return [
            'title' => 'Project pipeline',
            'columns' => [['key' => 'title', 'label' => 'Opportunity'], ['key' => 'party', 'label' => 'Client / prospect'], ['key' => 'stage', 'label' => 'Stage'], ['key' => 'engagement_type', 'label' => 'Type'], ['key' => 'expected_close', 'label' => 'Expected close'], ['key' => 'estimated_value', 'label' => 'Estimated value', 'numeric' => true]],
            'rows' => $leads->map(fn (Lead $l): array => ['title' => $l->title, 'party' => $l->partyName(), 'stage' => $l->stage, 'engagement_type' => $l->engagement_type, 'expected_close' => $l->expected_close?->toDateString(), 'estimated_value' => (string) $l->estimated_value->getAmount()])->values()->all(),
            'totals' => $totals, 'notes' => ['Open pipeline = new + qualified + proposal + negotiation.'],
        ];
    }

    /** @return array<string, mixed> */
    public function overdueMilestones(?User $user, Carbon $asOf): array
    {
        $ids = $this->visible($user);
        $rows = Milestone::query()->with('project.client')->where('status', '!=', 'done')->whereDate('due_on', '<', $asOf)
            ->when($ids !== '*', fn ($q) => $q->whereIn('project_id', $ids))->orderBy('due_on')->get()
            ->map(fn (Milestone $m): array => ['project' => "{$m->project->code} {$m->project->name}", 'client' => $m->project->client->name, 'milestone' => $m->title, 'due_on' => $m->due_on?->toDateString(),
                'days_overdue' => (int) $m->due_on?->diffInDays($asOf), 'status' => $m->status, 'blocker' => $m->blocker])->values()->all();

        return ['title' => 'Overdue milestones as at '.$asOf->toDateString(),
            'columns' => [['key' => 'project', 'label' => 'Project'], ['key' => 'client', 'label' => 'Client'], ['key' => 'milestone', 'label' => 'Milestone'], ['key' => 'due_on', 'label' => 'Due'], ['key' => 'days_overdue', 'label' => 'Days overdue'], ['key' => 'status', 'label' => 'Status'], ['key' => 'blocker', 'label' => 'Blocker']],
            'rows' => $rows, 'totals' => [], 'notes' => []];
    }

    /** @return array<string, mixed> */
    public function trainingRegister(?User $user, Carbon $from, Carbon $to): array
    {
        $ids = $this->visible($user);
        $rows = TrainingSession::query()->with('project.client')->withCount(['participants', 'participants as attended_count' => fn ($q) => $q->where('attended', true), 'participants as certified_count' => fn ($q) => $q->whereNotNull('certificate_document_id')])
            ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()])->when($ids !== '*', fn ($q) => $q->whereIn('project_id', $ids))->orderBy('session_date')->get()
            ->map(fn (TrainingSession $s): array => ['session_date' => $s->session_date->toDateString(), 'course' => $s->course_title, 'project' => $s->project->code, 'client' => $s->project->client->name, 'venue' => $s->venue,
                'status' => $s->status, 'participants' => (int) $s->getAttribute('participants_count'), 'attended' => (int) $s->getAttribute('attended_count'), 'certificates' => (int) $s->getAttribute('certified_count'), 'feedback' => $s->feedback_score])->values()->all();

        return ['title' => 'Training register '.$from->toDateString().' – '.$to->toDateString(),
            'columns' => [['key' => 'session_date', 'label' => 'Date'], ['key' => 'course', 'label' => 'Course'], ['key' => 'project', 'label' => 'Project'], ['key' => 'client', 'label' => 'Client'], ['key' => 'venue', 'label' => 'Venue'], ['key' => 'status', 'label' => 'Status'],
                ['key' => 'participants', 'label' => 'Participants'], ['key' => 'attended', 'label' => 'Attended'], ['key' => 'certificates', 'label' => 'Certificates'], ['key' => 'feedback', 'label' => 'Feedback (0–5)']],
            'rows' => $rows, 'totals' => [], 'notes' => ['Participant names and emails are personal data and are not included in this register.']];
    }

    /** @return list<int>|'*' */
    public function visible(?User $user): array|string
    {
        if ($user === null) {
            return '*';
        }

        return match ($this->permissions->scopeFor($user, 'projects', PermissionAction::View)) {
            RecordScope::All => '*',
            RecordScope::None => [],
            default => Project::assignedIds($user),
        };
    }
}
