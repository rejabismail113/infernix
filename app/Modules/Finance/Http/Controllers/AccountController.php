<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\ChartAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Chart of accounts (configurable; seeded PLACEHOLDER). @see SRS M01-06 */
final class AccountController
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ChartAccount::class);

        return Inertia::render('Finance/Accounts/Index', [
            'accounts' => ChartAccount::query()->orderBy('code')->get()->map(fn (ChartAccount $a): array => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type->value, 'subtype' => $a->subtype, 'system_key' => $a->system_key,
                'is_postable' => $a->is_postable, 'active' => $a->active, 'tax_treatment_default' => $a->tax_treatment_default, 'notes' => $a->notes,
            ]),
            'can' => ['create' => $request->user()?->can('create', ChartAccount::class) ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ChartAccount::class);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^\d{3,8}$/', Rule::unique('chart_accounts', 'code')],
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:asset,liability,equity,income,expense'],
            'subtype' => ['nullable', 'string', 'max:40'],
            'tax_treatment_default' => ['required', 'in:pending_review,deductible,non_deductible,capital'],
        ]);
        ChartAccount::query()->create($data + ['company_id' => $request->user()?->company_id, 'is_postable' => true, 'active' => true]);

        return back()->with('success', __('Account added.'));
    }

    public function update(Request $request, ChartAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);
        $data = $request->validate(['name' => ['required', 'string', 'max:190'], 'active' => ['boolean'], 'notes' => ['nullable', 'string', 'max:500']]);
        if (($data['active'] ?? true) === false && $account->system_key !== null) {
            return back()->with('error', __('System accounts used by posting rules cannot be deactivated.'));
        }
        $account->fill($data)->save();

        return back()->with('success', __('Account updated.'));
    }
}
