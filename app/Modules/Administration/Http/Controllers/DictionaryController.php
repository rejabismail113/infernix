<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Administration\Models\ClaimType;
use App\Modules\Administration\Models\ClaimTypeRate;
use App\Modules\Administration\Models\Dictionary;
use App\Modules\Administration\Models\DictionaryItem;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** @see SRS G-09, M12-05, M01-04 */
final class DictionaryController
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Dictionary::class);

        return Inertia::render('Admin/Dictionaries/Index', [
            'dictionaries' => Dictionary::query()->with('items')->orderBy('name')->get()->map(fn (Dictionary $d): array => [
                'id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'system' => $d->system,
                'items' => $d->items->map(fn (DictionaryItem $i): array => [
                    'id' => $i->id, 'code' => $i->code, 'label_en' => $i->label_en, 'label_ms' => $i->label_ms, 'meta' => $i->meta,
                    'effective_from' => $i->effective_from->toDateString(), 'effective_to' => $i->effective_to?->toDateString(), 'notes' => $i->notes, 'active' => $i->active,
                ]),
            ]),
            'claimTypes' => ClaimType::query()->with('rates')->orderBy('name')->get()->map(fn (ClaimType $c): array => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'receipt_required' => $c->receipt_required, 'is_medical' => $c->is_medical,
                'per_claim_limit' => $c->per_claim_limit?->getAmount()->__toString(), 'notes' => $c->notes, 'active' => $c->active,
                'rates' => $c->rates->map(fn (ClaimTypeRate $r): array => ['id' => $r->id, 'rate' => $r->rate->getAmount()->__toString(), 'unit' => $r->unit, 'version' => $r->version, 'effective_from' => $r->effective_from->toDateString(), 'effective_to' => $r->effective_to?->toDateString(), 'notes' => $r->notes]),
            ]),
            'can' => ['edit' => $request->user()?->can('update', Dictionary::class) ?? false],
        ]);
    }

    public function storeItem(Request $request, Dictionary $dictionary): RedirectResponse
    {
        $this->authorize('update', $dictionary);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'label_en' => ['required', 'string', 'max:190'],
            'label_ms' => ['nullable', 'string', 'max:190'],
            'meta' => ['nullable', 'array'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $dictionary->items()->create($data + ['active' => true]);

        return back()->with('success', __('Item added.'));
    }

    public function updateItem(Request $request, DictionaryItem $item): RedirectResponse
    {
        $this->authorize('update', $item);
        $data = $request->validate([
            'label_en' => ['required', 'string', 'max:190'],
            'label_ms' => ['nullable', 'string', 'max:190'],
            'meta' => ['nullable', 'array'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'notes' => ['nullable', 'string', 'max:500'],
            'active' => ['boolean'],
        ]);
        $item->fill($data)->save();

        return back()->with('success', __('Item updated.'));
    }

    /** New rate = new version; old version is end-dated the day before (rates are never edited in place). */
    public function storeRate(Request $request, ClaimType $claimType): RedirectResponse
    {
        $this->authorize('update', $claimType);
        $data = $request->validate([
            'rate' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'unit' => ['required', 'in:km,day,session,item'],
            'effective_from' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($claimType, $data): void {
            $latest = $claimType->rates()->orderByDesc('version')->first();
            if ($latest !== null) {
                $latest->forceFill(['effective_to' => $data['effective_from']])->save();
            }
            $claimType->rates()->create($data + ['version' => ($latest !== null ? $latest->version : 0) + 1]);
        });

        return back()->with('success', __('New rate version created.'));
    }
}
