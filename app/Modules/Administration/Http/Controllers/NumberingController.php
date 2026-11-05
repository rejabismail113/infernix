<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Numbering\Models\NumberingSequence;
use App\Modules\Administration\Models\Company;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** @see SRS G-03, M03-08, M12-05 */
final class NumberingController
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', NumberingSequence::class);

        return Inertia::render('Admin/Numbering/Index', [
            'sequences' => NumberingSequence::query()->orderBy('document_type')->orderByDesc('fiscal_year')->get()
                ->map(fn (NumberingSequence $s): array => ['id' => $s->id, 'document_type' => $s->document_type, 'fiscal_year' => $s->fiscal_year, 'prefix' => $s->prefix, 'format' => $s->format, 'next_number' => $s->next_number, 'padding' => $s->padding, 'preview' => $s->render($s->next_number)]),
            'can' => ['edit' => $request->user()?->can('update', NumberingSequence::class) ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('update', NumberingSequence::class);
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:32', 'regex:/^[A-Z_]+$/'],
            'fiscal_year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'prefix' => ['required', 'string', 'max:16'],
            'format' => ['required', 'string', 'max:64', 'regex:/\{seq\}/'],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
            'next_number' => ['required', 'integer', 'min:1'],
        ]);
        $data['company_id'] = Company::current()->id;
        $exists = NumberingSequence::query()->where('company_id', $data['company_id'])->where('document_type', $data['document_type'])->where('fiscal_year', $data['fiscal_year'])->first();
        if ($exists !== null) {
            // next_number may only move forward: never reuse numbers (§6.3).
            $data['next_number'] = max((int) $data['next_number'], $exists->next_number);
            $exists->fill($data)->save();
        } else {
            NumberingSequence::query()->create($data);
        }

        return back()->with('success', __('Numbering sequence saved.'));
    }

    public function update(Request $request, NumberingSequence $sequence): RedirectResponse
    {
        $this->authorize('update', $sequence);
        $data = $request->validate([
            'prefix' => ['required', 'string', 'max:16'],
            'format' => ['required', 'string', 'max:64', Rule::when(true, ['regex:/\{seq\}/'])],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
        ]);
        $sequence->fill($data)->save();

        return back()->with('success', __('Numbering sequence updated.'));
    }
}
