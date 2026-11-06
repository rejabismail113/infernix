<?php

declare(strict_types=1);

namespace App\Modules\Administration\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Secrets\SecretRedactor;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Finance\Services\LedgerGateway;
use App\Modules\HumanResources\Models\StaffProfile;
use App\Modules\HumanResources\Services\StaffService;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Projects\Models\Client;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Go-live data migration (§9.1): CSV importers for opening balances, clients, vendors, staff and assets.
 * Every row is validated first (types, required fields, duplicates in the file AND against existing data);
 * if any row fails NOTHING is written. `dry run` validates and reports only. A real run writes everything in one
 * transaction through the normal module services (numbering, encryption of identifiers, audit) and is audited.
 *
 * @see SRS §9.1, M12-01
 */
final class DataImportService
{
    public const TYPES = [
        'clients' => ['name', 'registration_no', 'tax_no', 'email', 'phone', 'contact_person', 'payment_terms_days'],
        'vendors' => ['name', 'registration_no', 'tax_no', 'email', 'phone', 'category', 'payment_terms_days', 'bank_reference'],
        'staff' => ['legal_name', 'preferred_name', 'engagement_type', 'classification', 'id_type', 'id_number', 'email', 'phone', 'position', 'joined_on', 'rate_type', 'rate_amount'],
        'assets' => ['name', 'category', 'serial_no', 'model', 'location', 'acquired_on', 'cost', 'useful_life_months', 'warranty_expires_on'],
        'opening_balances' => ['account_code', 'debit', 'credit', 'description'],
    ];

    private const MONEY = 'regex:/^-?\d{1,14}(\.\d{1,2})?$/';

    public function __construct(private readonly SecretRedactor $redactor) {}

    /**
     * @return array{type: string, dry_run: bool, rows: int, valid: int, created: int, errors: list<array{row: int, field: string, message: string}>, preview: list<array<string, string|null>>, journal_id?: int|null}
     */
    public function run(string $type, string $csv, User $actor, bool $dryRun = true, ?string $openingDate = null): array
    {
        if (! isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Unknown import type.');
        }
        [$rows, $errors] = $this->parse($type, $csv);
        $errors = array_merge($errors, $this->validate($type, $rows));
        $report = ['type' => $type, 'dry_run' => $dryRun, 'rows' => count($rows), 'valid' => count($rows) - count(array_unique(array_column($errors, 'row'))), 'created' => 0, 'errors' => $errors,
            'preview' => array_slice(array_map(fn (array $r): array => $this->mask($type, $r), $rows), 0, 20)];
        if ($errors !== [] || $dryRun || $rows === []) {
            Audit::log('import_validate', 'data_import', null, null, ['type' => $type, 'rows' => count($rows), 'errors' => count($errors), 'dry_run' => $dryRun], null, $actor);

            return $report;
        }
        try {
            DB::transaction(function () use ($type, $rows, $actor, $openingDate, &$report): void {
                if ($type === 'opening_balances') {
                    $report['journal_id'] = app(LedgerGateway::class)->postOpeningBalances(Carbon::parse($openingDate ?? today()->startOfYear()->toDateString()), array_map(fn (array $r): array => [
                        'account_code' => (string) $r['account_code'], 'debit' => (string) ($r['debit'] ?? ''), 'credit' => (string) ($r['credit'] ?? ''), 'description' => $r['description'] ?? null], $rows), $actor);
                    $report['created'] = count($rows);

                    return;
                }
                foreach ($rows as $r) {
                    $this->create($type, $r, $actor);
                    $report['created']++;
                }
            });
        } catch (Throwable $e) {
            $report['created'] = 0;
            $report['errors'][] = ['row' => 0, 'field' => '-', 'message' => 'Nothing was imported: '.$this->redactor->scrub($e->getMessage())];
        }
        Audit::log('import', 'data_import', null, null, ['type' => $type, 'rows' => count($rows), 'created' => $report['created'], 'failed' => $report['created'] === 0], null, $actor);

        return $report;
    }

    /** Header-only CSV template for a type. */
    public static function template(string $type): string
    {
        return implode(',', self::TYPES[$type])."\n";
    }

    /** @return array{0: list<array<string, string|null>>, 1: list<array{row: int, field: string, message: string}>} */
    private function parse(string $type, string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($csv)) ?: [], fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            return [[], [['row' => 1, 'field' => '-', 'message' => 'The file is empty.']]];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv(array_shift($lines)));
        $unknown = array_diff($header, self::TYPES[$type]);
        $errors = $unknown === [] ? [] : [['row' => 1, 'field' => implode(', ', $unknown), 'message' => 'Unknown column(s). Expected: '.implode(', ', self::TYPES[$type]).'.']];
        $rows = [];
        foreach ($lines as $i => $line) {
            if (count($rows) >= 5000) {
                $errors[] = ['row' => $i + 2, 'field' => '-', 'message' => 'At most 5,000 rows per file.'];
                break;
            }
            $cols = str_getcsv($line);
            $row = array_fill_keys(self::TYPES[$type], null); // absent optional columns = null
            foreach ($header as $k => $name) {
                $v = isset($cols[$k]) ? trim($cols[$k]) : '';
                $row[$name] = $v === '' ? null : $v;
            }
            $rows[] = $row;
        }

        return [$rows, $errors];
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return list<array{row: int, field: string, message: string}>
     */
    private function validate(string $type, array $rows): array
    {
        $rules = match ($type) {
            'clients' => ['name' => ['required', 'string', 'max:190'], 'registration_no' => ['nullable', 'string', 'max:60'], 'tax_no' => ['nullable', 'string', 'max:60'], 'email' => ['nullable', 'email'],
                'phone' => ['nullable', 'string', 'max:40'], 'contact_person' => ['nullable', 'string', 'max:190'], 'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365']],
            'vendors' => ['name' => ['required', 'string', 'max:190'], 'registration_no' => ['nullable', 'string', 'max:40'], 'tax_no' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email'],
                'phone' => ['nullable', 'string', 'max:40'], 'category' => ['nullable', 'string', 'max:40'], 'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'bank_reference' => ['nullable', 'string', 'max:60']],
            'staff' => ['legal_name' => ['required', 'string', 'max:190'], 'preferred_name' => ['nullable', 'string', 'max:190'], 'engagement_type' => ['required', 'in:employee,part_time,freelancer,intern'],
                'classification' => ['required', 'in:employee,contractor'], 'id_type' => ['nullable', 'in:nric,passport'], 'id_number' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'],
                'phone' => ['nullable', 'string', 'max:40'], 'position' => ['nullable', 'string', 'max:120'], 'joined_on' => ['nullable', 'date_format:Y-m-d'],
                'rate_type' => ['nullable', 'required_with:rate_amount', 'in:hourly,daily,session,fixed'], 'rate_amount' => ['nullable', 'required_with:rate_type', self::MONEY]],
            'assets' => ['name' => ['required', 'string', 'max:255'], 'category' => ['nullable', 'string'], 'serial_no' => ['nullable', 'string', 'max:120'], 'model' => ['nullable', 'string', 'max:120'],
                'location' => ['nullable', 'string', 'max:255'], 'acquired_on' => ['required', 'date_format:Y-m-d'], 'cost' => ['required', self::MONEY], 'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
                'warranty_expires_on' => ['nullable', 'date_format:Y-m-d']],
            default => ['account_code' => ['required', 'string', 'exists:chart_accounts,code'], 'debit' => ['nullable', self::MONEY], 'credit' => ['nullable', self::MONEY], 'description' => ['nullable', 'string', 'max:255']],
        };
        $errors = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $n = $i + 2; // 1-based incl. header
            $v = Validator::make($r, $rules);
            foreach ($v->errors()->messages() as $field => $msgs) {
                $errors[] = ['row' => $n, 'field' => $field, 'message' => $msgs[0]];
            }
            foreach ($r as $field => $value) {
                if (is_string($value) && $field !== 'bank_reference' && $field !== 'id_number' && $this->redactor->scrub($value) !== $value) {
                    $errors[] = ['row' => $n, 'field' => $field, 'message' => 'Looks like a password / secret; remove it.'];
                }
            }
            $key = match ($type) {
                'clients', 'vendors' => mb_strtolower((string) ($r['registration_no'] ?? $r['name'])),
                'staff' => mb_strtolower((string) ($r['id_number'] ?? $r['email'] ?? $r['legal_name'])),
                'assets' => mb_strtolower((string) ($r['serial_no'] ?? ('row'.$n))),
                default => 'row'.$n,
            };
            if (isset($seen[$key])) {
                $errors[] = ['row' => $n, 'field' => '-', 'message' => "Duplicate of row {$seen[$key]} in this file."];
            }
            $seen[$key] = $n;
            $exists = match ($type) {
                'clients' => Client::query()->where(fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower((string) $r['name'])])->when(! empty($r['registration_no']), fn ($w) => $w->orWhere('registration_no', $r['registration_no'])))->exists(),
                'vendors' => Vendor::query()->where(fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower((string) $r['name'])])->when(! empty($r['registration_no']), fn ($w) => $w->orWhere('registration_no', $r['registration_no'])))->exists(),
                'staff' => ! empty($r['email']) && StaffProfile::query()->where('email', $r['email'])->exists(),
                'assets' => ! empty($r['serial_no']) && DB::table('assets')->where('serial_no', $r['serial_no'])->exists(),
                default => false,
            };
            if ($exists) {
                $errors[] = ['row' => $n, 'field' => '-', 'message' => 'Already exists in Infernix One.'];
            }
            if ($type === 'assets' && ! empty($r['category']) && ! AssetCategory::query()->where('name', $r['category'])->exists()) {
                $errors[] = ['row' => $n, 'field' => 'category', 'message' => 'Unknown asset category.'];
            }
        }
        if ($type === 'opening_balances' && $rows !== []) {
            $dr = BigDecimal::zero();
            $cr = BigDecimal::zero();
            foreach ($rows as $r) {
                $dr = $dr->plus(preg_match('/^-?\d+(\.\d+)?$/', (string) $r['debit']) ? (string) $r['debit'] : '0');
                $cr = $cr->plus(preg_match('/^-?\d+(\.\d+)?$/', (string) $r['credit']) ? (string) $r['credit'] : '0');
            }
            if (! $dr->isEqualTo($cr)) {
                $errors[] = ['row' => 0, 'field' => 'debit/credit', 'message' => "Opening balances do not balance: debits {$dr} vs credits {$cr}."];
            }
        }

        return $errors;
    }

    /** @param  array<string, string|null>  $r */
    private function create(string $type, array $r, User $actor): void
    {
        match ($type) {
            'clients' => Client::query()->create(['company_id' => $actor->company_id, 'name' => $r['name'], 'registration_no' => $r['registration_no'], 'tax_no' => $r['tax_no'], 'email' => $r['email'],
                'phone' => $r['phone'], 'contact_person' => $r['contact_person'], 'payment_terms_days' => (int) ($r['payment_terms_days'] ?? 30), 'status' => 'active', 'created_by' => $actor->id]),
            'vendors' => Vendor::query()->create(['company_id' => $actor->company_id, 'name' => $r['name'], 'registration_no' => $r['registration_no'], 'tax_no' => $r['tax_no'], 'email' => $r['email'], 'phone' => $r['phone'],
                'contacts' => array_filter(['email' => $r['email'], 'phone' => $r['phone']]), 'category' => $r['category'], 'payment_terms_days' => (int) ($r['payment_terms_days'] ?? 30), 'bank_reference' => $r['bank_reference'], 'status' => 'active']),
            'staff' => $this->createStaff($r, $actor),
            'assets' => app(AssetService::class)->registerManual(['acquisition_type' => 'opening_balance', 'name' => $r['name'], 'category_id' => AssetCategory::query()->where('name', $r['category'])->value('id'),
                'serial_no' => $r['serial_no'], 'model' => $r['model'], 'location' => $r['location'], 'acquired_on' => $r['acquired_on'], 'cost' => $r['cost'],
                'useful_life_months' => $r['useful_life_months'] !== null ? (int) $r['useful_life_months'] : null, 'warranty_expires_on' => $r['warranty_expires_on']], $actor),
            default => null,
        };
    }

    /** @param  array<string, string|null>  $r */
    private function createStaff(array $r, User $actor): void
    {
        $svc = app(StaffService::class);
        $staff = $svc->create(['legal_name' => $r['legal_name'], 'preferred_name' => $r['preferred_name'], 'engagement_type' => $r['engagement_type'], 'classification' => $r['classification'],
            'id_type' => $r['id_type'] ?? 'nric', 'id_number' => $r['id_number'], 'email' => $r['email'], 'phone' => $r['phone'], 'position' => $r['position'], 'joined_on' => $r['joined_on']], $actor);
        if (! empty($r['rate_type']) && ! empty($r['rate_amount'])) {
            $svc->addEngagement($staff, ['rate_type' => $r['rate_type'], 'rate_amount' => $r['rate_amount'], 'effective_from' => $r['joined_on'] ?? today()->toDateString(), 'title' => 'Imported rate'], $actor);
        }
    }

    /**
     * Preview rows never echo identifiers or bank numbers in full (M04-06).
     *
     * @param  array<string, string|null>  $r
     * @return array<string, string|null>
     */
    private function mask(string $type, array $r): array
    {
        foreach (['id_number', 'bank_reference'] as $f) {
            if (! empty($r[$f])) {
                $r[$f] = str_repeat('*', max(0, mb_strlen((string) $r[$f]) - 4)).mb_substr((string) $r[$f], -4);
            }
        }

        return $r;
    }
}
