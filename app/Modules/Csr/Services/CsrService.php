<?php

declare(strict_types=1);

namespace App\Modules\Csr\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Crm\Contracts\ActivityDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Search\SearchIndexer;
use App\Modules\Csr\Exceptions\CsrRejected;
use App\Modules\Csr\Models\CsrEvidence;
use App\Modules\Csr\Models\CsrInKindContribution;
use App\Modules\Csr\Models\CsrProgramme;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CSR programmes (M08). Every programme gets an M07 activity (type csr) that finance lines are tagged to —
 * that is its cash spend (M08-02). In-kind items are a separate ledger of estimates (M08-03). Photos are
 * restricted documents with consent flags (M08-04).
 *
 * @see SRS M08-01, M08-02, M08-03, M08-04
 */
final class CsrService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly ActivityDirectory $activities,
        private readonly DocumentStore $documents,
        private readonly StaffDirectory $staff,
        private readonly SearchIndexer $search,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): CsrProgramme
    {
        return DB::transaction(function () use ($data, $actor): CsrProgramme {
            $date = (string) $data['programme_date'];
            $activityId = isset($data['activity_id']) ? (int) $data['activity_id']
                : $this->activities->createForCsr('CSR: '.$data['title'], $date, $data['venue'] ?? null, (string) ($data['budget'] ?? '0'), $actor);
            $p = CsrProgramme::query()->create($this->fields($data) + [
                'company_id' => $actor->company_id, 'programme_no' => $this->numbering->allocate((int) $actor->company_id, 'CSR_PROGRAMME', (int) Carbon::parse($date)->format('Y'))['formatted'],
                'activity_id' => $activityId, 'status' => 'planned', 'created_by' => $actor->id,
            ]);
            $this->index($p);

            return $p;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(CsrProgramme $p, array $data, User $actor): CsrProgramme
    {
        if (isset($data['status']) && ! in_array($data['status'], ['planned', 'ongoing', 'completed', 'cancelled'], true)) {
            throw new CsrRejected('Unknown status.');
        }
        $p->forceFill($this->fields($data) + array_intersect_key($data, array_flip(['status', 'actual_count', 'outcome'])))->save();
        $this->index($p);

        return $p;
    }

    /** @param  list<array{staff_profile_id?: int|null, name?: string|null, hours?: string|null, role?: string|null}>  $rows */
    public function saveVolunteers(CsrProgramme $p, array $rows, User $actor): void
    {
        DB::transaction(function () use ($p, $rows): void {
            $p->volunteers()->delete();
            foreach ($rows as $r) {
                $staffId = isset($r['staff_profile_id']) ? (int) $r['staff_profile_id'] : null;
                $name = $staffId !== null ? ($this->staff->snapshot($staffId)['name'] ?? throw new CsrRejected('Unknown staff member.')) : trim((string) ($r['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $p->volunteers()->create(['staff_profile_id' => $staffId, 'name' => $name, 'hours' => (string) BigDecimal::of(($r['hours'] ?? '') === '' ? '0' : (string) $r['hours'])->toScale(2), 'role' => $r['role'] ?? null]);
            }
        });
        Audit::log('volunteers', $p, null, null, ['count' => count($rows)], null, $actor);
    }

    /** @param  array{item: string, quantity?: string|null, estimated_value: string, source: string, valuation_basis?: string|null}  $data */
    public function addInKind(CsrProgramme $p, array $data, User $actor): CsrInKindContribution
    {
        return $p->inKind()->create(['item' => $data['item'], 'quantity' => (string) (($data['quantity'] ?? '') === '' ? '1' : $data['quantity']), 'estimated_value' => Money::myr($data['estimated_value']),
            'source' => $data['source'], 'valuation_basis' => $data['valuation_basis'] ?? null, 'created_by' => $actor->id]);
    }

    public function removeInKind(CsrInKindContribution $c, User $actor): void
    {
        Audit::log('delete', $c, null, ['item' => $c->item, 'estimated_value' => (string) $c->estimated_value->getAmount()], null, null, $actor);
        $c->delete();
    }

    /** Upload an evidence photo as a RESTRICTED document with consent metadata (M08-04). */
    public function addEvidence(CsrProgramme $p, UploadedFile $file, bool $identifiable, bool $consent, ?string $consentNote, User $actor): CsrEvidence
    {
        if ($identifiable && $consent && trim((string) $consentNote) === '') {
            throw new CsrRejected('Record how consent was obtained (e.g. signed consent form reference).');
        }

        return DB::transaction(function () use ($p, $file, $identifiable, $consent, $consentNote, $actor): CsrEvidence {
            $doc = $this->documents->attach($file, $actor, ['title' => "{$p->programme_no} evidence", 'classification' => 'restricted', 'tags' => ['csr', 'photo']], new LinkTarget('csr_programme', $p->id, 'photo'));

            return CsrEvidence::query()->create(['programme_id' => $p->id, 'document_id' => $doc->documentId, 'people_identifiable' => $identifiable, 'consent_obtained' => $consent,
                'consent_note' => $consentNote, 'publishable' => false, 'created_by' => $actor->id]);
        });
    }

    public function setPublishable(CsrEvidence $e, bool $publishable, User $actor): void
    {
        if ($publishable && $e->people_identifiable && ! $e->consent_obtained) {
            throw new CsrRejected('This photo shows identifiable people without recorded consent; it cannot be published (PDPA).');
        }
        $e->forceFill(['publishable' => $publishable])->save();
    }

    public function recordConsent(CsrEvidence $e, string $note, User $actor): void
    {
        $e->forceFill(['consent_obtained' => true, 'consent_note' => $note])->save();
        Audit::log('consent', $e, null, null, ['consent' => true], $note, $actor);
    }

    /**
     * Cash (finance lines tagged to the programme's activity) and in-kind kept apart (M08-02, M08-03).
     *
     * @return array{budget: string, cash: string, in_kind: string, volunteer_hours: string, by_kind: array<string, string>}
     */
    public function summary(CsrProgramme $p): array
    {
        $bva = $p->activity_id !== null ? $this->activities->budgetVsActual($p->activity_id) : ['budget' => '0.00', 'actual' => '0.00', 'by_kind' => []];
        $inKind = BigDecimal::zero();
        foreach ($p->inKind()->get() as $c) {
            $inKind = $inKind->plus((string) $c->estimated_value->getAmount());
        }
        $hours = BigDecimal::zero();
        foreach ($p->volunteers()->get() as $v) {
            $hours = $hours->plus($v->hours);
        }

        return ['budget' => $bva['budget'], 'cash' => $bva['actual'], 'in_kind' => (string) $inKind->toScale(2), 'volunteer_hours' => (string) $hours->toScale(2), 'by_kind' => $bva['by_kind']];
    }

    public function index(CsrProgramme $p): void
    {
        $this->search->index('csr_programme', $p->id, "{$p->programme_no} {$p->title}", trim(implode(' ', array_filter([$p->partner, $p->sponsor, $p->venue, $p->beneficiary_group]))), 'CSR');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(['title', 'objectives', 'partner', 'sponsor', 'venue', 'programme_date', 'beneficiary_group', 'target_count']));
    }
}
