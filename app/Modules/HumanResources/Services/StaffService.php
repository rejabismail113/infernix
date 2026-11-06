<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\HumanResources\Events\StaffLifecycleChanged;
use App\Modules\HumanResources\Exceptions\HrRejected;
use App\Modules\HumanResources\Models\Engagement;
use App\Modules\HumanResources\Models\StaffBankDetail;
use App\Modules\HumanResources\Models\StaffProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Staff profiles, restricted identifiers/bank details (masked; reveal audited) and versioned rate agreements.
 *
 * @see SRS M04-01, M04-05, M04-06, WF-05
 */
final class StaffService
{
    public function __construct(private readonly NumberingService $numbering) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): StaffProfile
    {
        return DB::transaction(function () use ($data, $actor): StaffProfile {
            $no = $this->numbering->allocate((int) $actor->company_id, 'STAFF', (int) today()->format('Y'))['formatted'];
            $staff = StaffProfile::query()->create($this->profileFields($data) + ['company_id' => $actor->company_id, 'staff_no' => $no, 'status' => 'active', 'created_by' => $actor->id]);
            if (isset($data['classification'])) {
                $staff->forceFill(['classification_reviewed_at' => now(), 'classification_reviewed_by' => $actor->id])->save();
            }
            DB::afterCommit(fn () => Event::dispatch(new StaffLifecycleChanged($staff->id, $staff->user_id, 'joiner', 'new staff profile', $actor->id)));

            return $staff;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(StaffProfile $staff, array $data, User $actor): StaffProfile
    {
        $fields = $this->profileFields($data, $staff);
        if (isset($fields['classification']) && $fields['classification'] !== $staff->classification) {
            $fields['classification_reviewed_at'] = now();
            $fields['classification_reviewed_by'] = $actor->id;
        }
        $before = ['status' => $staff->status, 'position' => $staff->position, 'engagement_type' => $staff->engagement_type, 'user_id' => $staff->user_id];
        $staff->forceFill($fields)->save();
        $kind = match (true) {
            $before['status'] === 'active' && $staff->status === 'inactive' => 'leaver',
            $before['status'] === 'inactive' && $staff->status === 'active' => 'joiner',
            $before['position'] !== $staff->position || $before['engagement_type'] !== $staff->engagement_type => 'mover',
            default => null,
        };
        if ($kind !== null) {
            Event::dispatch(new StaffLifecycleChanged($staff->id, $staff->user_id ?? $before['user_id'], $kind,
                $kind === 'mover' ? trim("{$before['position']} → {$staff->position}; {$before['engagement_type']} → {$staff->engagement_type}") : "status {$before['status']} → {$staff->status}", $actor->id));
        }

        return $staff;
    }

    /** Full NRIC/passport, for an authorised user, always audited with a reason (M04-06). */
    public function revealIdentifier(StaffProfile $staff, User $actor, string $reason): ?string
    {
        if (trim($reason) === '') {
            throw new HrRejected('A reason is required to reveal an identification number.');
        }
        Audit::log('reveal', $staff, null, null, ['field' => 'id_number'], $reason, $actor);

        return $staff->id_number;
    }

    /** @param  array{bank_name: string, account_no: string, account_name: string}  $data */
    public function setBank(StaffProfile $staff, array $data, User $actor): StaffBankDetail
    {
        $no = preg_replace('/\s+/', '', $data['account_no']) ?? '';
        if (strlen($no) < 6) {
            throw new HrRejected('Enter the full bank account number.');
        }

        return StaffBankDetail::query()->updateOrCreate(['staff_profile_id' => $staff->id], [
            'bank_name' => $data['bank_name'], 'account_no' => $no, 'account_name' => $data['account_name'], 'account_last4' => substr($no, -4), 'updated_by' => $actor->id,
        ]);
    }

    /** @return array{bank_name: string, account_no: string, account_name: string}|null */
    public function revealBank(StaffProfile $staff, User $actor, string $reason): ?array
    {
        if (trim($reason) === '') {
            throw new HrRejected('A reason is required to reveal bank details.');
        }
        $bank = $staff->bank;
        if ($bank === null) {
            return null;
        }
        Audit::log('reveal', $bank, null, null, ['field' => 'account_no'], $reason, $actor);

        return ['bank_name' => $bank->bank_name, 'account_no' => $bank->account_no, 'account_name' => $bank->account_name];
    }

    /**
     * New rate agreement version. Earlier open versions are closed the day before; locked versions keep their
     * rates forever (WF-05), only their end date is set.
     *
     * @param  array{rate_type: string, rate_amount: string, effective_from: string, title?: string|null, agreement_document_id?: int|null}  $data
     */
    public function addEngagement(StaffProfile $staff, array $data, User $actor): Engagement
    {
        return DB::transaction(function () use ($staff, $data, $actor): Engagement {
            $from = Carbon::parse($data['effective_from']);
            $latest = Engagement::query()->where('staff_profile_id', $staff->id)->lockForUpdate()->orderByDesc('version')->first();
            if ($latest !== null && $from->lte($latest->effective_from)) {
                throw new HrRejected("A new rate version must start after {$latest->effective_from->toDateString()} (version {$latest->version}).");
            }
            if ($latest !== null && ($latest->effective_to === null || $latest->effective_to->gte($from))) {
                $latest->forceFill(['effective_to' => $from->copy()->subDay()->toDateString()])->save();
            }

            return Engagement::query()->create([
                'staff_profile_id' => $staff->id, 'version' => (int) ($latest->version ?? 0) + 1, 'rate_type' => $data['rate_type'], 'rate_amount' => Money::myr($data['rate_amount']),
                'effective_from' => $from->toDateString(), 'title' => $data['title'] ?? null, 'agreement_document_id' => $data['agreement_document_id'] ?? null, 'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileFields(array $data, ?StaffProfile $existing = null): array
    {
        $keys = ['user_id', 'engagement_type', 'legal_name', 'preferred_name', 'id_type', 'email', 'phone', 'position', 'joined_on', 'ended_on', 'classification', 'notes', 'status'];
        $out = array_intersect_key($data, array_flip($keys));
        if (! empty($data['id_number'])) {
            $id = strtoupper(preg_replace('/[\s-]+/', '', (string) $data['id_number']) ?? '');
            if (($data['id_type'] ?? $existing->id_type ?? 'nric') === 'nric' && preg_match('/^\d{12}$/', $id) !== 1) {
                throw new HrRejected('An NRIC number has 12 digits.');
            }
            $out['id_number'] = $id;
            $out['id_last4'] = substr($id, -4);
        }

        return $out;
    }
}
