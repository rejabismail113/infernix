<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Presenters;

use App\Modules\Administration\Models\ClaimType;
use App\Modules\Administration\Models\DictionaryItem;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\ChartAccount;
use App\Modules\Procurement\Models\Vendor;

/** Option lists for finance forms (only what the form needs; no secrets). */
final class FinanceOptions
{
    /** @return list<array{id: int, label: string}> */
    public static function banks(): array
    {
        return BankAccount::query()->with('companyAccount')->get()->map(fn (BankAccount $b): array => ['id' => $b->id, 'label' => $b->label()])->values()->all();
    }

    /** @return list<array{id: int, label: string}> */
    public static function dictionary(string $code): array
    {
        return DictionaryItem::query()->whereRelation('dictionary', 'code', $code)->effective()->orderBy('sort_order')->get()
            ->map(fn (DictionaryItem $i): array => ['id' => $i->id, 'label' => $i->label_en])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public static function claimTypes(): array
    {
        return ClaimType::query()->where('active', true)->orderBy('name')->get()->map(fn (ClaimType $t): array => [
            'id' => $t->id, 'code' => $t->code, 'label' => $t->name, 'receipt_required' => $t->receipt_required, 'is_medical' => $t->is_medical,
            'rate' => $t->rateOn()?->rate->getAmount()->__toString(), 'unit' => $t->rateOn()?->unit,
        ])->values()->all();
    }

    /** @return list<array{id: int, label: string}> */
    public static function vendors(): array
    {
        return Vendor::query()->where('status', 'active')->orderBy('name')->get()->map(fn (Vendor $v): array => ['id' => $v->id, 'label' => $v->name])->values()->all();
    }

    /** @return list<array{id: int, label: string, type: string}> */
    public static function postableAccounts(): array
    {
        return ChartAccount::query()->where('is_postable', true)->where('active', true)->orderBy('code')->get()
            ->map(fn (ChartAccount $a): array => ['id' => $a->id, 'label' => $a->label(), 'type' => $a->type->value])->values()->all();
    }
}
