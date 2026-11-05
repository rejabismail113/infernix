<?php

declare(strict_types=1);

namespace App\Modules\Administration;

use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Administration\Models\ClaimTypeRate;
use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\CompanyBankAccount;
use App\Modules\Administration\Models\Dictionary;
use App\Modules\Administration\Models\DictionaryItem;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Administration\Models\FiscalYear;
use App\Modules\Administration\Models\NotificationRule;
use App\Modules\Administration\Policies\ClaimTypePolicy;
use App\Modules\Administration\Policies\ClaimTypeRatePolicy;
use App\Modules\Administration\Policies\CompanyBankAccountPolicy;
use App\Modules\Administration\Policies\CompanyPolicy;
use App\Modules\Administration\Policies\DictionaryItemPolicy;
use App\Modules\Administration\Policies\DictionaryPolicy;
use App\Modules\Administration\Policies\FiscalPeriodPolicy;
use App\Modules\Administration\Policies\FiscalYearPolicy;
use App\Modules\Administration\Policies\NotificationRulePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M12 — Administration, Master Data & Security (UI/config over the kernel).
 *
 * @see SRS §4 M12
 * @see docs/ARCHITECTURE.md
 */
final class AdministrationServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M12';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');

        foreach ([
            Company::class => CompanyPolicy::class,
            CompanyBankAccount::class => CompanyBankAccountPolicy::class,
            FiscalYear::class => FiscalYearPolicy::class,
            FiscalPeriod::class => FiscalPeriodPolicy::class,
            Dictionary::class => DictionaryPolicy::class,
            DictionaryItem::class => DictionaryItemPolicy::class,
            ClaimType::class => ClaimTypePolicy::class,
            ClaimTypeRate::class => ClaimTypeRatePolicy::class,
            NotificationRule::class => NotificationRulePolicy::class,
        ] as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Company-level documents (SSM registration, logo, signature, contracts) — M06-01.
        $this->app->make(DocumentLinkRegistry::class)->register(new LinkableEntityType(
            type: 'company',
            modelClass: Company::class,
            label: 'Company',
            linkTypes: ['ssm_registration', 'logo', 'signature', 'contract', 'agreement', 'certificate', 'report', 'evidence', 'other'],
            evidenceSubjects: fn (string $condition): array => $condition === 'always'
                ? Company::query()->orderBy('id')->pluck('name', 'id')->all()
                : [],
        ));
    }
}
