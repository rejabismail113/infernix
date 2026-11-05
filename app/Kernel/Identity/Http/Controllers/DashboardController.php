<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** Placeholder landing page; real dashboards arrive with M11 (Prompt 3). */
final class DashboardController
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'company' => config('infernix.company'),
        ]);
    }
}
