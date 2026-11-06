<?php

declare(strict_types=1);

namespace App\Modules\Administration\Services;

use App\Kernel\Approval\ApprovalMatrix;
use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Enums\RoleCode;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Administration\Exceptions\AdministrationRejected;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Approval matrices are configurable and versioned (M12-03): a change publishes a NEW version with an effective
 * date; earlier versions are never edited (they end where the new one starts), so every past approval stays
 * explainable. Level 1 must cover every amount from RM 0 upwards without gaps or overlaps; higher levels add
 * approvals for the bands they cover. Approver roles exclude STF and AUD (an auditor never approves).
 *
 * @see SRS M12-03, UAT-11
 */
final class ApprovalMatrixService
{
    public const APPROVER_ROLES = ['OWN', 'FIN', 'PM', 'HR', 'APP'];

    /**
     * @param  list<array{level:int|string, min_amount:string, max_amount:?string, approver_roles:list<string>}>  $rules
     */
    public function publish(string $entityType, array $rules, Carbon $effectiveFrom, string $reason, User $actor): ApprovalMatrix
    {
        if (trim($reason) === '') {
            throw new AdministrationRejected('A reason is required to change an approval matrix.');
        }
        if ($effectiveFrom->lt(today())) {
            throw new AdministrationRejected('A new version cannot take effect in the past.');
        }
        $current = ApprovalMatrix::query()->where('entity_type', $entityType)->orderByDesc('version')->with('rules')->first();
        if ($current === null) {
            throw new AdministrationRejected('Unknown approval entity type.');
        }
        $normalised = $this->validate($rules);

        return DB::transaction(function () use ($entityType, $normalised, $effectiveFrom, $reason, $actor, $current): ApprovalMatrix {
            ApprovalMatrix::query()->where('entity_type', $entityType)->whereNull('effective_to')->lockForUpdate()->get()
                ->each(fn (ApprovalMatrix $m) => $m->forceFill(['effective_to' => $effectiveFrom])->save());
            /** @var ApprovalMatrix $new */
            $new = ApprovalMatrix::query()->create(['entity_type' => $entityType, 'version' => $current->version + 1, 'effective_from' => $effectiveFrom,
                'notes' => $reason, 'created_by' => $actor->id]);
            foreach ($normalised as $r) {
                $new->rules()->create($r);
            }
            Audit::log('permission_change', $new, null, ['entity_type' => $entityType, 'version' => $current->version, 'rules' => $current->rules->map(fn ($r): array => [
                'level' => $r->level, 'min' => (string) $r->min_amount->getAmount(), 'max' => $r->max_amount === null ? null : (string) $r->max_amount->getAmount(), 'roles' => $r->approver_roles])->all()],
                ['entity_type' => $entityType, 'version' => $new->version, 'effective_from' => $effectiveFrom->toDateString(), 'rules' => $normalised], $reason, $actor);

            return $new->load('rules');
        });
    }

    /**
     * @param  list<array{level:int|string, min_amount:string, max_amount:?string, approver_roles:list<string>}>  $rules
     * @return list<array{level:int, min_amount:string, max_amount:?string, approver_roles:list<string>}>
     */
    private function validate(array $rules): array
    {
        if ($rules === []) {
            throw new AdministrationRejected('Add at least one rule.');
        }
        $out = [];
        foreach ($rules as $i => $r) {
            $n = $i + 1;
            $roles = array_values(array_unique(array_map('strval', $r['approver_roles'])));
            if ($roles === [] || array_diff($roles, self::APPROVER_ROLES) !== []) {
                throw new AdministrationRejected("Rule {$n}: choose approver roles from ".implode(', ', self::APPROVER_ROLES).'.');
            }
            $min = Money::myr($r['min_amount']);
            $max = ($r['max_amount'] ?? '') === '' || $r['max_amount'] === null ? null : Money::myr((string) $r['max_amount']);
            if ($min->isNegative() || ($max !== null && $max->isLessThan($min))) {
                throw new AdministrationRejected("Rule {$n}: the amount range is invalid.");
            }
            $out[] = ['level' => (int) $r['level'], 'min_amount' => (string) $min->getAmount(), 'max_amount' => $max === null ? null : (string) $max->getAmount(), 'approver_roles' => $roles];
        }
        $levels = array_values(array_unique(array_column($out, 'level')));
        sort($levels);
        if ($levels !== range(1, count($levels))) {
            throw new AdministrationRejected('Levels must start at 1 and have no gaps.');
        }
        foreach ($levels as $level) {
            $bands = array_values(array_filter($out, fn (array $r): bool => $r['level'] === $level));
            usort($bands, fn (array $a, array $b): int => Money::myr($a['min_amount'])->compareTo(Money::myr($b['min_amount'])));
            for ($k = 1, $c = count($bands); $k < $c; $k++) {
                $prevMax = $bands[$k - 1]['max_amount'];
                if ($prevMax === null || Money::myr($bands[$k]['min_amount'])->isLessThanOrEqualTo(Money::myr($prevMax))) {
                    throw new AdministrationRejected("Level {$level}: amount ranges overlap.");
                }
                if ($level === 1 && ! Money::myr($bands[$k]['min_amount'])->isEqualTo(Money::myr($prevMax)->plus(Money::myr('0.01')))) {
                    throw new AdministrationRejected('Level 1: amount ranges must be continuous (next range starts 0.01 after the previous one ends).');
                }
            }
            if ($level === 1 && (! Money::myr($bands[0]['min_amount'])->isZero() || end($bands)['max_amount'] !== null)) {
                throw new AdministrationRejected('Level 1 must cover every amount: start at 0.00 and leave the last range open-ended.');
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function roleOptions(): array
    {
        return array_values(array_filter(array_map(fn (RoleCode $r): string => $r->value, RoleCode::cases()), fn (string $r): bool => in_array($r, self::APPROVER_ROLES, true)));
    }
}
