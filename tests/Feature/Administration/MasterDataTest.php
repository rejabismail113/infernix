<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\Role;
use App\Kernel\Numbering\Models\NumberingSequence;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\Dictionary;
use App\Modules\Administration\Models\DictionaryItem;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Administration\Models\NotificationRule;
use Illuminate\Support\Carbon;

/** @see SRS G-03, G-09, M12-05, M01-04 */
it('seeds roles, matrix, company, fiscal periods, numbering, dictionaries and notification rules', function (): void {
    expect(Role::query()->pluck('code')->all())->toBe(['OWN', 'FIN', 'PM', 'HR', 'STF', 'AUD', 'APP'])
        ->and(Company::current()->name)->toBe('Infernix Evolv Hub')
        ->and(FiscalPeriod::query()->count())->toBe(24)
        ->and(NumberingSequence::query()->count())->toBeGreaterThanOrEqual(30)
        ->and(DictionaryItem::query()->where('notes', 'like', 'PLACEHOLDER%')->count())->toBe(DictionaryItem::query()->count())
        ->and(ClaimType::query()->where('notes', 'like', 'PLACEHOLDER%')->count())->toBe(ClaimType::query()->count())
        ->and(NotificationRule::query()->where('event', 'SubscriptionRenewalDue')->pluck('lead_days')->sort()->values()->all())->toBe([30, 60, 90]);
})->group('G-03', 'G-09', 'G-05', 'M12-05');

it('versions claim rates: a new rate ends the previous version and lines can snapshot the right one', function (): void {
    $mileage = ClaimType::query()->where('code', 'mileage')->firstOrFail();
    $v1 = $mileage->rateOn(Carbon::parse('2026-03-01'));
    expect($v1?->version)->toBe(1)->and($v1?->rate->getAmount()->__toString())->toBe('0.60');

    actingAsSatisfied(ownerUser())->post(route('admin.claim-types.rates.store', $mileage), ['rate' => '0.75', 'unit' => 'km', 'effective_from' => '2026-07-01'])->assertSessionHasNoErrors();

    expect($mileage->rateOn(Carbon::parse('2026-06-30'))?->version)->toBe(1)
        ->and($mileage->rateOn(Carbon::parse('2026-07-01'))?->version)->toBe(2)
        ->and($mileage->rateOn(Carbon::parse('2026-07-01'))?->rate->getAmount()->__toString())->toBe('0.75')
        ->and($mileage->rates()->where('version', 1)->value('effective_to')?->format('Y-m-d'))->toBe('2026-07-01');
})->group('M01-04', 'G-09', '§2.2');

it('resolves dictionary items by effective date', function (): void {
    $dict = Dictionary::query()->where('code', 'expense_category')->firstOrFail();
    actingAsSatisfied(ownerUser())->post(route('admin.dictionaries.items.store', $dict), ['code' => 'future_item', 'label_en' => 'Future', 'effective_from' => '2099-01-01'])->assertSessionHasNoErrors();
    expect(DictionaryItem::query()->effective()->where('code', 'future_item')->exists())->toBeFalse()
        ->and(DictionaryItem::query()->effective(Carbon::parse('2099-06-01'))->where('code', 'future_item')->exists())->toBeTrue();
})->group('G-09', 'M12-05');

it('masks company bank account numbers in the UI payload', function (): void {
    $owner = ownerUser();
    actingAsSatisfied($owner)->post(route('admin.company.bank-accounts.store'), ['bank_name' => 'CIMB', 'account_no' => '514012345678', 'account_name' => 'Infernix Evolv Hub'])->assertSessionHasNoErrors();
    $page = actingAsSatisfied($owner)->get(route('admin.company.edit'))->assertOk();
    $accounts = $page->viewData('page')['props']['bankAccounts'];
    expect(collect($accounts)->firstWhere('bank_name', 'CIMB')['account_no_masked'])->toBe('********5678')
        ->and(json_encode($accounts))->not->toContain('514012345678');
})->group('M04-06', 'NFR-07', 'G-03');
