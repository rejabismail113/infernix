<?php

declare(strict_types=1);

namespace App\Modules\Procurement;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Crm\Contracts\PurchasingDirectory;
use App\Kernel\Crm\Contracts\VendorDirectory;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Notifications\PendingApprovalRegistry;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Kernel\Search\SearchRegistry;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Models\Subscription;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Policies\GoodsReceiptPolicy;
use App\Modules\Procurement\Policies\PurchaseRequestPolicy;
use App\Modules\Procurement\Policies\SubscriptionPolicy;
use App\Modules\Procurement\Policies\VendorPolicy;
use App\Modules\Procurement\Reports\ProcurementReports;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\VendorsDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M10 — Procurement, Vendors & Subscriptions.
 *
 * @see SRS §4 M10
 * @see docs/ARCHITECTURE.md
 */
final class ProcurementServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M10';

    public function register(): void
    {
        $this->app->bind(VendorDirectory::class, VendorsDirectory::class);
        $this->app->bind(PurchasingDirectory::class, GoodsReceiptService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        foreach ([Vendor::class => VendorPolicy::class, PurchaseRequest::class => PurchaseRequestPolicy::class, GoodsReceipt::class => GoodsReceiptPolicy::class, Subscription::class => SubscriptionPolicy::class] as $m => $p) {
            Gate::policy($m, $p);
        }

        $registry = $this->app->make(DocumentLinkRegistry::class);
        $registry->register(new LinkableEntityType('vendor', Vendor::class, 'Vendor', ['contract', 'agreement', 'ssm_registration', 'attachment', 'other'], labelFor: fn (Vendor $v): string => $v->name));
        $registry->register(new LinkableEntityType('purchase_request', PurchaseRequest::class, 'Purchase request', ['evidence', 'attachment', 'other'], labelFor: fn (PurchaseRequest $p): string => $p->pr_no));
        $registry->register(new LinkableEntityType('subscription', Subscription::class, 'Subscription', ['contract', 'invoice', 'attachment', 'other'], labelFor: fn (Subscription $s): string => $s->product));
        $search = $this->app->make(SearchRegistry::class);
        $search->register('vendor', Vendor::class, 'Vendor', fn (Model $v): string => route('procurement.vendors.show', $v->getKey()));
        $search->register('purchase_request', PurchaseRequest::class, 'Purchase request', fn (Model $p): string => route('procurement.requests.show', $p->getKey()));
        $search->register('subscription', Subscription::class, 'Subscription', fn (Model $s): string => route('procurement.subscriptions.index'));

        $catalog = $this->app->make(ReportCatalog::class);
        $range = fn (array $f, string $defaultTo): array => [Carbon::parse($f['from'] ?? today()->startOfYear()->toDateString()), Carbon::parse($f['to'] ?? $defaultTo)];
        $catalog->register(new ReportDefinition('procurement.vendor-spending', 'Vendor spending', 'Procurement', 'bills', fn (array $f): array => app(ProcurementReports::class)->vendorSpending(...$range($f, today()->toDateString()))));
        $catalog->register(new ReportDefinition('procurement.pipeline', 'Purchase pipeline', 'Procurement', 'purchase_requests', fn (array $f): array => app(ProcurementReports::class)->pipeline(...$range($f, today()->toDateString()))));
        $catalog->register(new ReportDefinition('procurement.renewal-calendar', 'Subscription renewal calendar', 'Procurement', 'subscriptions',
            fn (array $f): array => app(ProcurementReports::class)->renewalCalendar(Carbon::parse($f['from'] ?? today()->toDateString()), Carbon::parse($f['to'] ?? today()->addYear()->toDateString()))));

        $this->app->make(PendingApprovalRegistry::class)->register('procurement', function (): array {
            $items = [];
            foreach (PurchaseRequest::query()->where('status', 'pending_approval')->limit(200)->get() as $pr) {
                $items[] = ['key' => "purchase_request:{$pr->id}:L{$pr->current_level}", 'title' => "Purchase request {$pr->pr_no} (RM {$pr->estimated_total->getAmount()})", 'url' => route('procurement.requests.show', $pr->id),
                    'roles' => app(ApprovalService::class)->rolesFor('purchase_request', $pr->estimated_total, $pr->current_level), 'preparer_id' => $pr->requested_by];
            }

            return $items;
        });
    }
}
