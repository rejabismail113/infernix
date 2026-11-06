<?php

declare(strict_types=1);

use App\Console\Commands\OpenApiCommand;
use Illuminate\Routing\Router;

/** docs/openapi.json is generated from the routes + docs/PERMISSION_MATRIX.md and must be committed current. */
it('NFR-11: the committed OpenAPI document matches the route table and the permission matrix', function (): void {
    $this->artisan('api:openapi', ['--check' => true])->assertSuccessful();
    $spec = OpenApiCommand::build(app(Router::class));
    $ops = collect($spec['paths'])->flatMap(fn (array $p) => array_values($p));
    // Every operation carries its role matrix; idempotent money endpoints declare the header.
    expect($ops->whereNull('x-route-matrix')->count())->toBe(0)
        ->and(collect($spec['paths']['/finance/payments']['post']['parameters'])->pluck('name'))->toContain('Idempotency-Key');
})->group('NFR-11');
