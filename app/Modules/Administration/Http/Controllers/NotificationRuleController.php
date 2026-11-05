<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Identity\Enums\RoleCode;
use App\Modules\Administration\Models\NotificationRule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** @see SRS G-05 */
final class NotificationRuleController
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', NotificationRule::class);

        return Inertia::render('Admin/NotificationRules/Index', [
            'rules' => NotificationRule::query()->orderBy('event')->orderBy('lead_days')->get(),
            'roles' => array_map(fn (RoleCode $r) => $r->value, RoleCode::cases()),
            'can' => ['edit' => $request->user()?->can('update', NotificationRule::class) ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', NotificationRule::class);
        NotificationRule::query()->create($this->validated($request));

        return back()->with('success', __('Rule saved.'));
    }

    public function update(Request $request, NotificationRule $rule): RedirectResponse
    {
        $this->authorize('update', $rule);
        $rule->fill($this->validated($request))->save();

        return back()->with('success', __('Rule updated.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'event' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z]+$/'],
            'target_roles' => ['nullable', 'array'],
            'target_roles.*' => [Rule::in(array_map(fn (RoleCode $r) => $r->value, RoleCode::cases()))],
            'target_user_ids' => ['nullable', 'array'],
            'target_user_ids.*' => ['integer', 'exists:users,id'],
            'channel' => ['required', 'in:email,in_app,both'],
            'lead_days' => ['required', 'integer', 'min:0', 'max:365'],
            'active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
