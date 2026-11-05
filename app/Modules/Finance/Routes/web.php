<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * M01 Finance routes. Every route MUST be wrapped in auth + a policy/permission check
 * (deny-by-default, CLAUDE.md rule 3). Routes are added in later prompts.
 */
Route::middleware(['web', 'app'])->prefix('finance')->name('finance.')->group(function (): void {
    //
});
