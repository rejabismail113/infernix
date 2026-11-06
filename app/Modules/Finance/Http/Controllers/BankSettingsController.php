<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Kernel\Audit\Audit;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Models\User;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\BankImportProfile;
use App\Modules\Finance\Models\BankMatchRule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bank statement import templates (CSV column mapping per bank) and auto-match rules (M01-07).
 *
 * @see SRS M01-07, WF-08
 */
final class BankSettingsController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', BankAccount::class);

        return Inertia::render('Finance/Bank/Settings', [
            'profiles' => BankImportProfile::query()->orderBy('name')->get()->map(fn (BankImportProfile $p): array => ['id' => $p->id, 'name' => $p->name, 'template_key' => $p->template_key, 'bank_name' => $p->bank_name,
                'column_mapping' => $p->column_mapping, 'date_format' => $p->date_format, 'has_header' => $p->has_header, 'delimiter' => $p->delimiter, 'skip_rows' => $p->skip_rows, 'notes' => $p->notes, 'active' => $p->active]),
            'rules' => BankMatchRule::query()->orderBy('priority')->get()->map(fn (BankMatchRule $r): array => ['id' => $r->id, 'name' => $r->name, 'bank_account_id' => $r->bank_account_id, 'priority' => $r->priority,
                'direction' => $r->direction, 'date_window_days' => $r->date_window_days, 'reference_pattern' => $r->reference_pattern, 'payee_alias' => $r->payee_alias, 'ledger_keyword' => $r->ledger_keyword, 'active' => $r->active, 'notes' => $r->notes]),
            'accounts' => BankAccount::query()->with('companyAccount')->get()->map(fn (BankAccount $b): array => ['id' => $b->id, 'label' => $b->label()]),
            'can' => ['edit' => $this->user($request)->can('update', BankAccount::class)],
        ]);
    }

    public function saveProfile(Request $request, ?BankImportProfile $profile = null): RedirectResponse
    {
        $this->authorize('update', BankAccount::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'bank_name' => ['nullable', 'string', 'max:60'], 'date_format' => ['required', 'string', 'max:20', Rule::in(['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'm/d/Y', 'd M Y', 'd.m.Y'])],
            'has_header' => ['boolean'], 'delimiter' => ['required', 'in:",",;,|,tab'], 'skip_rows' => ['nullable', 'integer', 'min:0', 'max:20'], 'notes' => ['nullable', 'string', 'max:500'], 'active' => ['boolean'],
            'column_mapping' => ['required', 'array'], 'column_mapping.*' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);
        $map = array_filter(array_intersect_key($data['column_mapping'], array_flip(['date', 'description', 'reference', 'amount', 'debit', 'credit', 'balance'])), fn ($v) => $v !== null && $v !== '');
        if (! isset($map['date'], $map['description']) || (! isset($map['amount']) && ! (isset($map['debit']) && isset($map['credit'])))) {
            throw ValidationException::withMessages(['column_mapping' => __('Map at least date, description and either amount or debit + credit columns.')]);
        }
        $fields = ['name' => $data['name'], 'bank_name' => $data['bank_name'] ?? null, 'date_format' => $data['date_format'], 'has_header' => (bool) ($data['has_header'] ?? true),
            'delimiter' => $data['delimiter'] === 'tab' ? "\t" : $data['delimiter'], 'skip_rows' => (int) ($data['skip_rows'] ?? 0), 'notes' => $data['notes'] ?? null, 'active' => (bool) ($data['active'] ?? true),
            'column_mapping' => array_map('intval', $map)];
        $profile ??= new BankImportProfile;
        $profile->forceFill($fields)->save();
        Audit::log('save', $profile, null, null, ['name' => $profile->name], null, $this->user($request));

        return back()->with('success', __('Import template saved.'));
    }

    public function saveRule(Request $request, ?BankMatchRule $rule = null): RedirectResponse
    {
        $this->authorize('update', BankAccount::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'], 'priority' => ['required', 'integer', 'min:1', 'max:999'],
            'direction' => ['required', 'in:any,in,out'], 'date_window_days' => ['required', 'integer', 'min:0', 'max:60'], 'reference_pattern' => ['nullable', 'string', 'max:200'],
            'payee_alias' => ['nullable', 'string', 'max:120'], 'ledger_keyword' => ['nullable', 'string', 'max:120'], 'active' => ['boolean'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        if (! empty($data['reference_pattern']) && @preg_match($data['reference_pattern'], '') === false) {
            throw ValidationException::withMessages(['reference_pattern' => __('Not a valid regular expression (e.g. /\\b(INV-\\d{4}-\\d{4})\\b/i).')]);
        }
        $rule ??= new BankMatchRule(['created_by' => $this->user($request)->id]);
        $rule->forceFill($data + ['active' => (bool) ($data['active'] ?? true)])->save();
        Audit::log('save', $rule, null, null, ['name' => $rule->name], null, $this->user($request));

        return back()->with('success', __('Match rule saved.'));
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
