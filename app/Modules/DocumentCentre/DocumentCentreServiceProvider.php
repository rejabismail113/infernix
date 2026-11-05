<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre;

use App\Kernel\Documents\Contracts\DocumentScanner;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Policies\DocumentPolicy;
use App\Modules\DocumentCentre\Services\DocumentService;
use App\Modules\DocumentCentre\Services\Scanners\ClamAvScanner;
use App\Modules\DocumentCentre\Services\Scanners\NullScanner;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M06 — Document Centre. Implements Kernel\Documents contracts (DocumentStore, DocumentScanner).
 *
 * @see SRS §4 M06
 * @see docs/ARCHITECTURE.md
 */
final class DocumentCentreServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M06';

    public function register(): void
    {
        $this->app->bind(DocumentScanner::class, fn () => match (config('infernix.documents.scanner')) {
            'clamav' => new ClamAvScanner((string) config('infernix.documents.clamav_binary')),
            default => new NullScanner,
        });
        $this->app->bind(DocumentStore::class, DocumentService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        Gate::policy(Document::class, DocumentPolicy::class);

        // Validated morph map for entity_document_links (only registered entity types).
        $this->app->booted(function (): void {
            Relation::morphMap($this->app->make(DocumentLinkRegistry::class)->morphMap());
        });
    }
}
