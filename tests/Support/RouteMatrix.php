<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Identity\Models\User;
use App\Modules\Activities\Services\ActivityService;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Administration\Models\Dictionary;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Administration\Models\NotificationRule;
use App\Modules\Assets\Models\AssetCandidate;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Assets\Services\DisposalService;
use App\Modules\Certifications\Models\Renewal;
use App\Modules\Certifications\Services\CertificationService;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Models\DocumentTemplate;
use App\Modules\Commercial\Models\DocumentTemplateVersion;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\Commercial\Services\GeneratorService;
use App\Modules\Commercial\Services\ReceiptService;
use App\Modules\Credentials\Models\JmlChecklist;
use App\Modules\Credentials\Services\AccessService;
use App\Modules\Credentials\Services\CredentialService;
use App\Modules\Csr\Services\CsrService;
use App\Modules\DocumentCentre\Models\DocumentVersion;
use App\Modules\Finance\Models\BankImportProfile;
use App\Modules\Finance\Models\BankMatchRule;
use App\Modules\Finance\Models\ChartAccount;
use App\Modules\Finance\Services\BankService;
use App\Modules\Finance\Services\ClaimService;
use App\Modules\HumanResources\Models\PaymentBatch;
use App\Modules\HumanResources\Services\TimesheetService;
use App\Modules\Procurement\Models\SubscriptionReview;
use App\Modules\Procurement\Services\PurchaseRequestService;
use App\Modules\Procurement\Services\SubscriptionService;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Projects\Services\TrainingService;
use App\Modules\Reporting\Models\ReportSchedule;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

/**
 * Route × role security matrix (NFR-02). Every registered route is called by one user per role against fixtures
 * owned by OTHER people (so record scope / IDOR is exercised), with an empty payload. Codes:
 *   A = the action ran (2xx / 3xx, no errors)   V = passed authorization, stopped by validation (422 / error bag)
 *   R = refused by a domain or approval-authority rule (flash error)   D = denied (401 / 403)
 *   N = not found (404 — record hidden by scope, signed link refused, or bogus token)   X = server error (fails).
 * The expected matrix lives in docs/PERMISSION_MATRIX.md between the route-matrix markers; ROUTE_MATRIX_WRITE=1
 * regenerates it. tests/Security/RouteMatrixTest.php fails on any unlisted route, stale row or drift.
 *
 * @see SRS NFR-02, UAT-01, UAT-10, M06-02
 */
final class RouteMatrix
{
    public const ROLES = ['OWN', 'FIN', 'PM', 'HR', 'STF', 'AUD', 'APP'];

    public const START = '<!-- route-matrix:start -->';

    public const END = '<!-- route-matrix:end -->';

    public const LEGEND = '**A** = the action ran (2xx/3xx). **V** = authorized but stopped by validation (empty payload). **R** = refused by a domain / approval-authority / maker-checker rule. **D** = denied (401/403). **N** = not found (404: record hidden by scope, signed link refused or bogus token). A server error fails the suite; a registered route with no row here fails the suite.';

    /** Routes deliberately excluded from probing (framework internals with no app data). */
    public const SKIP = ['horizon.index', 'sanctum.csrf-cookie', 'storage.local', 'ignition.healthCheck', 'ignition.executeSolution', 'ignition.updateConfig'];

    /**
     * {param} routes a plain staff member / applicant may legitimately reach for a record they do not own,
     * each reviewed: the action itself is scoped (own notifications, a validation 422 before any lookup, …).
     */
    public const IDOR_ALLOW = [
        'invitations.accept',  // public: the {token} IS the credential; a bogus token renders "invalid / expired"
        'invitations.store',   // public: validation (422) runs before the token lookup, which then refuses
        'password.reset',      // public: renders the reset form; the token is checked on submit
    ];

    /** @return list<Route> */
    public static function routes(): array
    {
        return array_values(array_filter(RouteFacade::getRoutes()->getRoutes(), fn (Route $r): bool => ! in_array($r->getName(), self::SKIP, true) && ! str_starts_with($r->uri(), '_')));
    }

    public static function key(Route $r): string
    {
        return $r->getName() ?? (implode('|', array_diff($r->methods(), ['HEAD'])).' '.$r->uri());
    }

    public static function method(Route $r): string
    {
        $m = array_values(array_diff($r->methods(), ['HEAD']));

        return in_array('GET', $m, true) ? 'GET' : $m[0];
    }

    /** @return array<string, int|string> fixture ids keyed by "param" or "routePrefix:param" */
    public static function fixtures(): array
    {
        $owner = ownerUser();
        $f = [];
        $project = makeProject(userWithRoles('PM'));
        $f['project'] = $project->id;
        $f['client'] = $project->client_id;
        $staff = makeStaff(userWithRoles('STF'));
        $f['staff'] = $staff->id;
        $ps = app(ProjectService::class);
        $f['lead'] = $ps->saveLead(['client_id' => $project->client_id, 'title' => 'Matrix lead', 'engagement_type' => 'project', 'estimated_value' => '1000.00'], $owner)->id;
        $m = $ps->saveMilestone($project, ['title' => 'Matrix milestone', 'due_on' => today()->addDays(10)->toDateString()], $owner);
        $f['milestone'] = $m->id;
        $f['task'] = $ps->saveTask($m, ['title' => 'Matrix task'], $owner)->id;
        $f['member'] = $ps->addMember($project, ['staff_profile_id' => $staff->id, 'role' => 'member', 'from_date' => '2026-01-01'], $owner)->id;
        $f['session'] = app(TrainingService::class)->saveSession($project, ['course_title' => 'Matrix course', 'session_date' => today()->addDays(5)->toDateString()], $owner)->id;
        $f['timesheet'] = app(TimesheetService::class)->create($staff, today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString(), $owner)->id;
        $f['batch'] = PaymentBatch::query()->create(['company_id' => $owner->company_id, 'batch_no' => 'PB-MATRIX', 'period_end' => today()->toDateString(), 'status' => 'draft', 'payout_method' => 'voucher', 'total_amount' => '0', 'created_by' => $owner->id])->id;

        $invoice = issuedInvoice('1000.00', $project->client()->firstOrFail(), ['project_id' => $project->id]);
        $docs = app(CommercialDocumentService::class);
        $f['commercial.invoices:document'] = $invoice->id;
        $f['commercial.quotations:document'] = $docs->createDraft(DocumentType::Quotation, ['client_id' => $project->client_id, 'title' => 'Q', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00']]], $owner)->id;
        $f['commercial.credit-notes:document'] = $docs->draftCreditNote($invoice, $owner)->id;
        $vendor = makeVendor('Matrix vendor');
        $f['vendor'] = $vendor->id;
        $f['commercial.vendor-pos:document'] = $docs->createDraft(DocumentType::VendorPo, ['vendor_id' => $vendor->id, 'title' => 'PO', 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00']]], $owner)->id;
        $f['commercial.generated:document'] = app(GeneratorService::class)->createDraft(DocumentType::Memo, ['staff_profile_id' => $staff->id, 'subject' => 'Matrix memo', 'body' => 'Body'], $owner)->id;
        $f['memo'] = (int) DB::table('staff_memos')->insertGetId(['staff_profile_id' => $staff->id, 'commercial_document_id' => app(GeneratorService::class)->createDraft(DocumentType::Memo, ['staff_profile_id' => $staff->id, 'subject' => 'Delivered memo', 'body' => 'Body'], $owner)->id, 'subject' => 'Matrix memo', 'delivered_at' => now()]);
        $f['receipt'] = app(ReceiptService::class)->record(['receipt_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'amount' => '100.00', 'method' => 'transfer', 'client_id' => $invoice->client_id, 'payer_name' => 'Client',
            'allocations' => [['invoice_id' => $invoice->id, 'amount' => '100.00']]], userWithRoles('FIN'))->id;
        $template = DocumentTemplate::query()->firstOrFail();
        $f['template'] = $template->id;
        $f['commercial.templates:version'] = (int) DocumentTemplateVersion::query()->where('document_template_id', $template->id)->value('id');
        $f['commercial.reports:report'] = 'invoice-register';
        $f['outbox'] = (int) DB::table('einvoice_outbox')->insertGetId(['invoice_id' => $invoice->id, 'idempotency_key' => 'matrix-'.$invoice->id, 'gateway' => 'stub', 'payload' => '{}', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);

        $doc = storeDocument($owner, ['title' => 'Matrix document', 'classification' => 'confidential']);
        $f['documents:document'] = $doc->id;
        $f['document'] = $doc->id;
        $f['documents:version'] = (int) DocumentVersion::query()->where('document_id', $doc->id)->value('id');
        $f['share'] = (int) DB::table('document_shares')->insertGetId(['document_id' => $doc->id, 'user_id' => $owner->id, 'permission' => 'view', 'shared_by' => $owner->id, 'created_at' => now()]);
        $f['tag'] = 'matrix';

        $txn = postedTransaction();
        $f['transaction'] = $txn->id;
        $f['journal'] = (int) $txn->journal_id;
        $f['bank'] = bank()->id;
        $f['finance.bank:transaction'] = app(BankService::class)->addManual(bank(), ['txn_date' => today()->toDateString(), 'description' => 'Matrix', 'amount' => '-1.00'], $owner)->id;
        $f['profile'] = (int) BankImportProfile::query()->value('id');
        $f['finance.bank.rules:rule'] = (int) (BankMatchRule::query()->value('id') ?? BankMatchRule::query()->create(['name' => 'r', 'priority' => 1, 'direction' => 'any', 'date_window_days' => 3, 'active' => true, 'created_by' => $owner->id])->id);
        $f['admin.notification-rules:rule'] = (int) NotificationRule::query()->value('id');
        $f['account'] = (int) ChartAccount::query()->where('code', '5040')->value('id');
        $f['finance.reports:report'] = 'trial-balance';
        $f['period'] = (int) FiscalPeriod::query()->orderBy('starts_on')->value('id');
        $stfClaimant = userWithRoles('STF');
        $f['claim'] = app(ClaimService::class)->create($stfClaimant, ['purpose' => 'Matrix claim', 'lines' => [['claim_type_id' => (int) ClaimType::query()->where('code', 'mileage')->value('id'), 'incurred_on' => today()->toDateString(), 'description' => 'x', 'distance_km' => '10']]], $stfClaimant)->id;
        $bill = approvedVendorBill($vendor, [['description' => 'Laptop', 'quantity' => '1', 'unit_price' => '3000.00', 'capitalization_class' => 'CAPITAL_ASSET']], 'MATRIX-1');
        $f['bill'] = $bill->id;
        $f['payment'] = payVendorBill($bill, '3000.00')->id;

        $f['claimType'] = (int) ClaimType::query()->value('id');
        $f['dictionary'] = (int) Dictionary::query()->value('id');
        $f['item'] = (int) DB::table('dictionary_items')->value('id');
        $f['sequence'] = (int) DB::table('numbering_sequences')->value('id');
        $f['user'] = userWithRoles('STF')->id;
        $f['delegation'] = (int) DB::table('approval_delegations')->insertGetId(['delegator_id' => $owner->id, 'delegate_id' => userWithRoles('FIN')->id, 'entity_type' => 'claim', 'max_amount' => '1000', 'valid_from' => today(), 'valid_to' => today()->addDays(5), 'reason' => 'leave', 'version' => 1, 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $f['notification'] = (string) Str::uuid();
        $owner->notifications()->create(['id' => $f['notification'], 'type' => 'matrix', 'data' => ['title' => 'x', 'url' => '/']]);
        $f['schedule'] = ReportSchedule::query()->create(['report_key' => 'finance.trial-balance', 'format' => 'csv', 'frequency' => 'daily', 'recipient_ids' => [$owner->id], 'active' => true, 'created_by' => $owner->id])->id;
        $f['key'] = 'finance.trial-balance';
        $f['type'] = 'clients';

        $assets = app(AssetService::class);
        $f['candidate'] = (int) AssetCandidate::query()->where('source_id', $bill->id)->value('id');
        $asset = $assets->registerManual(['acquisition_type' => 'donation', 'name' => 'Matrix asset', 'cost' => '900.00', 'acquired_on' => '2026-01-01'], $owner);
        $f['asset'] = $asset->id;
        $f['maintenance'] = $assets->logMaintenance($asset, ['kind' => 'service', 'reported_on' => today()->toDateString(), 'description' => 'x'], $owner)->id;
        $f['disposal'] = app(DisposalService::class)->create($asset, ['method' => 'scrap', 'disposal_date' => today()->toDateString(), 'reason' => 'x'], $owner)->id;
        $f['activity'] = app(ActivityService::class)->create(['type' => 'trip', 'title' => 'Matrix trip', 'start_date' => today()->toDateString(), 'end_date' => today()->toDateString()], $owner)->id;
        $csr = app(CsrService::class);
        $prog = $csr->create(['title' => 'Matrix CSR', 'programme_date' => today()->toDateString()], $owner);
        $f['programme'] = $prog->id;
        $f['contribution'] = $csr->addInKind($prog, ['item' => 'x', 'estimated_value' => '10.00', 'source' => 'y'], $owner)->id;
        $f['evidence'] = $csr->addEvidence($prog, Files::jpg('m.jpg'), false, false, null, $owner)->id;
        $certs = app(CertificationService::class);
        $f['certification'] = $certs->saveCertification(null, ['holder_type' => 'company', 'name' => 'Matrix cert', 'issuer' => 'x', 'expiry_date' => today()->addYear()->toDateString()], $owner)->id;
        $f['membership'] = $certs->saveMembership(null, ['holder_type' => 'company', 'organisation' => 'Matrix assoc', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31'], $owner)->id;
        $f['renewal'] = (int) Renewal::query()->where('renewable_type', 'certification')->where('renewable_id', $f['certification'])->value('id');
        $f['purchaseRequest'] = app(PurchaseRequestService::class)->save(null, ['title' => 'Matrix PR', 'justification' => 'x', 'lines' => [['description' => 'x', 'quantity' => '1', 'estimated_unit_price' => '10.00']]], $owner)->id;
        $sub = app(SubscriptionService::class)->save(null, ['vendor_id' => $vendor->id, 'product' => 'Matrix SaaS', 'seats' => 1, 'price' => '10.00', 'currency' => 'MYR', 'billing_cycle' => 'annual', 'renewal_date' => today()->addDays(30)->toDateString()], $owner);
        $f['subscription'] = $sub->id;
        $f['review'] = SubscriptionReview::query()->create(['subscription_id' => $sub->id, 'renewal_date' => $sub->renewal_date->toDateString(), 'alert_level' => 30, 'status' => 'open'])->id;
        $cred = app(CredentialService::class)->save(null, ['system_name' => 'Matrix credential', 'category' => 'cloud', 'environment' => 'production', 'owner_id' => $owner->id, 'vault_provider' => 'none', 'sensitivity' => 'high'], $owner);
        $f['credential'] = $cred->id;
        $requester = userWithRoles('PM');
        $f['accessRequest'] = app(AccessService::class)->request($cred, $requester, 'need it for the matrix', null)->id;
        $f['entitlement'] = app(AccessService::class)->grant($cred, userWithRoles('FIN'), $owner)->id;
        $f['checklist'] = (int) (JmlChecklist::query()->value('id') ?? 0);
        $f['token'] = str_repeat('a', 40);
        $f['id'] = 'matrix-job';

        return $f;
    }

    /** @param  array<string, int|string>  $fixtures */
    public static function url(Route $route, array $fixtures): string
    {
        $params = [];
        $prefix = (string) $route->getName();
        foreach ($route->parameterNames() as $p) {
            $value = null;
            for ($pre = $prefix; $pre !== ''; $pre = str_contains($pre, '.') ? substr($pre, 0, (int) strrpos($pre, '.')) : '') {
                if (isset($fixtures["{$pre}:{$p}"])) {
                    $value = $fixtures["{$pre}:{$p}"];
                    break;
                }
            }
            $value ??= $fixtures[$p] ?? (str_ends_with($route->uri(), '?}') ? null : 999999);
            if ($value !== null) {
                $params[$p] = $value;
            }
        }
        $uri = $route->uri();
        foreach ($params as $k => $v) {
            $uri = (string) preg_replace('/\{'.preg_quote($k, '/').'\??\}/', rawurlencode((string) $v), $uri);
        }

        return '/'.ltrim((string) preg_replace('/\{\w+\?\}/', '', $uri), '/');
    }

    /**
     * @param  array<string, mixed>  $errors  the validation error bag left in the session (redirect responses)
     */
    public static function classify(int $status, array $errors = [], bool $flashError = false): string
    {
        return match (true) {
            $status >= 500 => 'X',
            $status === 401, $status === 403 => 'D',
            $status === 404 => 'N',
            $status === 422 => 'V',
            $flashError || array_key_exists('general', $errors) => 'R',
            $errors !== [] => 'V',
            default => 'A',
        };
    }

    /** @return array<string, array{method: string, uri: string, codes: array<string, string>}> */
    public static function parseDoc(): array
    {
        $doc = (string) file_get_contents(base_path('docs/PERMISSION_MATRIX.md'));
        $start = strpos($doc, self::START);
        $end = strpos($doc, self::END);
        if ($start === false || $end === false) {
            return [];
        }
        $rows = [];
        foreach (explode("\n", substr($doc, $start, $end - $start)) as $line) {
            if (! str_starts_with($line, '| `')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            $rows[trim($cells[0], '`')] = ['method' => $cells[1], 'uri' => trim($cells[2], '`'), 'codes' => array_combine(self::ROLES, array_slice($cells, 3, 7)) ?: []];
        }

        return $rows;
    }

    /** @param  array<string, array{method: string, uri: string, codes: array<string, string>}>  $rows */
    public static function writeDoc(array $rows): void
    {
        ksort($rows);
        $table = self::START."\n| Route | Method | URI | ".implode(' | ', self::ROLES)." |\n|---|---|---|".str_repeat('---|', count(self::ROLES))."\n";
        foreach ($rows as $key => $r) {
            $table .= "| `{$key}` | {$r['method']} | `{$r['uri']}` | ".implode(' | ', array_map(fn (string $role): string => $r['codes'][$role], self::ROLES))." |\n";
        }
        $table .= self::END;
        $path = base_path('docs/PERMISSION_MATRIX.md');
        $doc = (string) file_get_contents($path);
        $start = strpos($doc, self::START);
        $end = strpos($doc, self::END);
        $doc = $start !== false && $end !== false
            ? substr($doc, 0, $start).$table.substr($doc, $end + strlen(self::END))
            : rtrim($doc)."\n\n## Route × role matrix (generated — do not edit by hand)\n\nGenerated by `ROUTE_MATRIX_WRITE=1 php artisan test tests/Security/RouteMatrixTest.php`. Each route is called by one user per role, with an empty payload, against records owned by someone else. "
                .self::LEGEND."\n\n".$table."\n";
        file_put_contents($path, $doc);
    }

    public static function resetThrottles(): void
    {
        Cache::flush();
    }
}
