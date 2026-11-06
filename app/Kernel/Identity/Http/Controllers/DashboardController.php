<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** Landing page: shortcuts to everything the user may open (dashboards live in M11). */
final class DashboardController
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'company' => config('infernix.company'),
        ]);
    }
}
