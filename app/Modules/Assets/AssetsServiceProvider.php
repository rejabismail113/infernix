<?php

declare(strict_types=1);

namespace App\Modules\Assets;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Notifications\PendingApprovalRegistry;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Kernel\Search\SearchRegistry;
use App\Modules\Assets\Listeners\RecordAssetCandidate;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDisposal;
use App\Modules\Assets\Policies\AssetDisposalPolicy;
use App\Modules\Assets\Policies\AssetPolicy;
use App\Modules\Assets\Reports\AssetReports;
use App\Modules\Finance\Events\AssetQualifyingPurchaseRecorded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M05 — Assets.
 *
 * @see SRS §4 M05
 * @see docs/ARCHITECTURE.md
 */
final class AssetsServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M05';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(AssetDisposal::class, AssetDisposalPolicy::class);
        Event::listen(AssetQualifyingPurchaseRecorded::class, RecordAssetCandidate::class);

        $this->app->make(DocumentLinkRegistry::class)->register(new LinkableEntityType('asset', Asset::class, 'Asset', ['receipt', 'warranty', 'handover', 'photo', 'evidence', 'attachment', 'other'],
            labelFor: fn (Asset $a): string => "{$a->asset_no} {$a->name}"));
        $this->app->make(SearchRegistry::class)->register('asset', Asset::class, 'Asset', fn (Model $a): string => route('assets.assets.show', $a->getKey()));

        $catalog = $this->app->make(ReportCatalog::class);
        $range = fn (array $f): array => [Carbon::parse($f['from'] ?? today()->startOfYear()->toDateString()), Carbon::parse($f['to'] ?? today()->endOfYear()->toDateString())];
        $catalog->register(new ReportDefinition('assets.register', 'Asset register', 'Assets', 'assets', fn (array $f): array => app(AssetReports::class)->register(Carbon::parse($f['as_of'] ?? today()->toDateString())), ['as_of']));
        $catalog->register(new ReportDefinition('assets.custodians', 'Custodian list', 'Assets', 'assets', fn (array $f): array => app(AssetReports::class)->custodians(), []));
        $catalog->register(new ReportDefinition('assets.warranty', 'Warranty expiries', 'Assets', 'assets', fn (array $f): array => app(AssetReports::class)->warrantyExpiries(...$range($f))));
        $catalog->register(new ReportDefinition('assets.disposals', 'Disposal history', 'Assets', 'assets', fn (array $f): array => app(AssetReports::class)->disposals(...$range($f))));

        $this->app->make(PendingApprovalRegistry::class)->register('assets', function (): array {
            $items = [];
            foreach (AssetDisposal::query()->with('asset')->where('status', 'pending_approval')->limit(200)->get() as $d) {
                $items[] = ['key' => "asset_disposal:{$d->id}:L{$d->current_level}", 'title' => "Asset disposal {$d->disposal_no} ({$d->asset->asset_no})", 'url' => route('assets.assets.show', $d->asset_id),
                    'roles' => app(ApprovalService::class)->rolesFor('asset_disposal', $d->asset->cost, $d->current_level), 'preparer_id' => $d->created_by];
            }

            return $items;
        });
    }
}
