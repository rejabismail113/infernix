<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Finance\Services\PeriodCloseService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Month-end close checklist; the lock itself posts to the audited, MFA-gated admin route. @see SRS M01-08, WF-08 */
final class PeriodCloseController
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $this->authorize('viewAny', FiscalPeriod::class);

        return Inertia::render('Finance/Periods/Index', [
            'periods' => FiscalPeriod::query()->with('fiscalYear')->orderBy('starts_on')->get()->map(fn (FiscalPeriod $p): array => [
                'id' => $p->id, 'label' => $p->fiscalYear->year.' P'.$p->period_no, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(),
                'locked_at' => $p->locked_at?->toIso8601String(), 'current' => today()->between($p->starts_on, $p->ends_on),
            ]),
        ]);
    }

    public function show(Request $request, FiscalPeriod $period, PeriodCloseService $close): Response
    {
        $this->authorize('view', $period);
        $period->load('fiscalYear');

        return Inertia::render('Finance/Periods/Show', [
            'period' => ['id' => $period->id, 'label' => $period->fiscalYear->year.' P'.$period->period_no, 'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString(), 'locked_at' => $period->locked_at?->toIso8601String(), 'lock_reason' => $period->lock_reason],
            'checklist' => $close->checklist($period),
            'can' => ['lock' => $request->user()?->can('approve', $period) ?? false],
        ]);
    }
}
