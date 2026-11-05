<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Administration\Models\FiscalYear;
use App\Modules\Administration\Services\FiscalPeriodService;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** @see SRS G-03, M01-08, M12-05, UAT-09 */
final class FiscalPeriodController
{
    use AuthorizesRequests;

    public function __construct(private readonly FiscalPeriodService $periods) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FiscalPeriod::class);
        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('Admin/FiscalPeriods/Index', [
            'years' => FiscalYear::query()->with('periods')->orderByDesc('year')->get()->map(fn (FiscalYear $y): array => [
                'id' => $y->id, 'year' => $y->year, 'status' => $y->status,
                'periods' => $y->periods->map(fn (FiscalPeriod $p): array => [
                    'id' => $p->id, 'period_no' => $p->period_no, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(),
                    'locked_at' => $p->locked_at?->toIso8601String(), 'lock_reason' => $p->lock_reason,
                ]),
            ]),
            'can' => ['lock' => $actor->can('approve', FiscalPeriod::class), 'create' => $actor->can('create', FiscalPeriod::class)],
        ]);
    }

    public function storeYear(Request $request): RedirectResponse
    {
        $this->authorize('create', FiscalPeriod::class);
        $data = $request->validate(['year' => ['required', 'integer', 'min:2020', 'max:2100', 'unique:fiscal_years,year']]);
        $this->periods->createYear(Company::current()->id, (int) $data['year']);

        return back()->with('success', __('Fiscal year created with 12 periods.'));
    }

    public function lock(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $this->authorize('approve', $period);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $this->periods->lock($period, $actor, $data['reason']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', __('Period locked.'));
    }

    public function unlock(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $this->authorize('approve', $period);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $this->periods->unlock($period, $actor, $data['reason']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', __('Period unlocked.'));
    }
}
