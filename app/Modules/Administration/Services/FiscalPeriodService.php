<?php

declare(strict_types=1);

namespace App\Modules\Administration\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Enums\RoleCode;
use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Administration\Models\FiscalYear;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lock/unlock fiscal periods — OWN/FIN only, always audited with reason (the controller adds MFA re-auth).
 *
 * @see SRS M01-08, M12-05, UAT-09, WF-08
 */
final class FiscalPeriodService
{
    public function lock(FiscalPeriod $period, User $actor, string $reason): FiscalPeriod
    {
        $this->assertMayLock($actor);
        if ($period->isLocked()) {
            throw new DomainException('Period is already locked.');
        }

        return DB::transaction(function () use ($period, $actor, $reason): FiscalPeriod {
            $period->forceFill(['locked_at' => now(), 'locked_by' => $actor->id, 'lock_reason' => $reason])->save();
            Audit::log('period_lock', $period, null, ['locked_at' => null], ['locked_at' => $period->locked_at?->toIso8601String()], $reason, $actor);

            return $period;
        });
    }

    public function unlock(FiscalPeriod $period, User $actor, string $reason): FiscalPeriod
    {
        $this->assertMayLock($actor);
        if (! $period->isLocked()) {
            throw new DomainException('Period is not locked.');
        }

        return DB::transaction(function () use ($period, $actor, $reason): FiscalPeriod {
            $was = $period->locked_at?->toIso8601String();
            $period->forceFill(['locked_at' => null, 'locked_by' => null, 'lock_reason' => null])->save();
            Audit::log('period_unlock', $period, null, ['locked_at' => $was], ['locked_at' => null], $reason, $actor);

            return $period;
        });
    }

    /** Creates a calendar fiscal year with 12 monthly periods. */
    public function createYear(int $companyId, int $year): FiscalYear
    {
        return DB::transaction(function () use ($companyId, $year): FiscalYear {
            $fy = FiscalYear::query()->create([
                'company_id' => $companyId, 'year' => $year,
                'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31", 'status' => 'open',
            ]);
            for ($m = 1; $m <= 12; $m++) {
                $start = Carbon::create($year, $m, 1);
                $fy->periods()->create(['period_no' => $m, 'starts_on' => $start->toDateString(), 'ends_on' => $start->copy()->endOfMonth()->toDateString()]);
            }

            return $fy;
        });
    }

    private function assertMayLock(User $actor): void
    {
        if (! $actor->hasRole(RoleCode::Owner, RoleCode::Finance)) {
            throw new AuthorizationException('Only OWN or FIN may lock or unlock periods.');
        }
    }
}
