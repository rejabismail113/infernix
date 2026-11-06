<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Kernel\Identity\Models\Role;
use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\DictionaryItem;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Administration\Services\FiscalPeriodService;
use App\Modules\Assets\Models\AssetCandidate;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Models\CommercialDocument;
use App\Modules\Commercial\Models\Quotation;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\Commercial\Services\ReceiptService;
use App\Modules\Credentials\Services\CredentialService;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Services\BillService;
use App\Modules\Finance\Services\ClaimService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Finance\Services\TransactionService;
use App\Modules\HumanResources\Services\StaffService;
use App\Modules\HumanResources\Services\TimesheetService;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\SubscriptionService;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * UAT walk-through data (docs/UAT_EVIDENCE.md, docs/ADMIN_MANUAL.md): one scenario per UAT-01…12, created through
 * the real services so every rule (maker-checker, approvals, numbering, postings, links) applies. DEV / UAT ONLY —
 * refuses to run in production, and every demo user shares SEED_DEMO_PASSWORD. All amounts and names are fictitious.
 *
 *   php artisan db:seed --class=UatDemoSeeder      (make demo)
 *
 * @see SRS §9 (UAT-01 … UAT-12)
 */
final class UatDemoSeeder extends Seeder
{
    /** Same dev-only TOTP secret as the seeded owner, so the Playwright evidence run can sign in as FIN. */
    private const DEV_MFA_SECRET = 'JBSWY3DPEHPK3PXP';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('UatDemoSeeder is for development / UAT only.');
        }
        if (! Client::query()->where('name', 'Demo: Cahaya Learning Sdn Bhd')->exists()) {
            DB::transaction(function (): void {
                $this->seedScenarios();
            });
        }
        $this->registerDemoAsset();
        $this->command?->info('UAT demo data created. Users: demo.{fin1,fin2,pm,hr,stf1,stf2,aud}@infernix.local / SEED_DEMO_PASSWORD.');
    }

    private function seedScenarios(): void
    {
        $owner = User::query()->where('email', config('infernix.seed_owner.email'))->firstOrFail();
        $u = fn (string $key, string $name, string $role): User => $this->user("demo.{$key}@infernix.local", $name, $role);
        $fin1 = $u('fin1', 'Farah (Finance maker)', 'FIN');
        $fin2 = $u('fin2', 'Hakim (Finance checker)', 'FIN');
        $pm = $u('pm', 'Priya (Project manager)', 'PM');
        $u('hr', 'Hana (HR)', 'HR');
        $stf1 = $u('stf1', 'Sam (Trainer)', 'STF');
        $stf2 = $u('stf2', 'Siti (Trainer)', 'STF');
        $u('aud', 'Adam (Auditor)', 'AUD');
        $bank = BankAccount::query()->firstOrFail();
        $docs = app(CommercialDocumentService::class);

        // UAT-06: quotation → accepted → project → invoice, client and scope carried over.
        $client = Client::query()->create(['company_id' => Company::current()->id, 'name' => 'Demo: Cahaya Learning Sdn Bhd', 'registration_no' => '202601009999', 'email' => 'accounts@cahaya.example',
            'address' => ['line1' => 'Jalan Tun Razak 10', 'city' => 'Kuala Lumpur', 'postcode' => '50400'], 'payment_terms_days' => 30, 'status' => 'active', 'created_by' => $owner->id]);
        /** @var Quotation $q */
        $q = $docs->createDraft(DocumentType::Quotation, ['client_id' => $client->id, 'title' => 'Leadership programme 2026 (3 cohorts)',
            'lines' => [['description' => 'Leadership programme — 3 cohorts × 2 days', 'quantity' => '1', 'unit' => 'lot', 'unit_price' => '10000.00']]], $fin1);
        $this->approve($docs->submit($q, $fin1), $fin2, $owner);
        /** @var Quotation $q */
        $q = $docs->issue($q->fresh(), $fin2);
        $q = $docs->recordClientResponse($q, true, $fin2, 'Accepted by email');
        $projectId = $docs->convertToProject($q, $owner, 'Demo: Cahaya leadership programme');
        $project = Project::query()->findOrFail($projectId);
        app(ProjectService::class)->update($project, ['pm_user_id' => $pm->id], $owner);

        // UAT-04: invoice RM 10,000, receipt RM 3,000 → outstanding RM 7,000.
        $invoice = $docs->convertToInvoice($q->fresh(), $fin1);
        $this->approve($docs->submit($invoice, $fin1), $fin2, $owner);
        $invoice = $docs->issue($invoice->fresh(), $fin2);
        app(ReceiptService::class)->record(['receipt_date' => today()->toDateString(), 'bank_account_id' => $bank->id, 'amount' => '3000.00', 'method' => 'transfer', 'client_id' => $client->id,
            'payer_name' => $client->name, 'reference' => 'IBG 20260301', 'allocations' => [['invoice_id' => $invoice->id, 'amount' => '3000.00']]], $fin1);

        // UAT-07: Sam is assigned to the project (timesheets allowed); Siti is not.
        $staff = app(StaffService::class);
        $sam = $staff->create(['user_id' => $stf1->id, 'engagement_type' => 'part_time', 'legal_name' => 'Samuel anak Jimbun', 'id_type' => 'nric', 'id_number' => '880808-13-5555',
            'email' => $stf1->email, 'position' => 'Trainer', 'joined_on' => '2026-01-01', 'classification' => 'contractor'], $owner);
        $staff->addEngagement($sam, ['rate_type' => 'daily', 'rate_amount' => '600.00', 'effective_from' => '2026-01-01', 'title' => 'Trainer agreement v1'], $owner);
        $siti = $staff->create(['user_id' => $stf2->id, 'engagement_type' => 'part_time', 'legal_name' => 'Siti Nurhaliza binti Omar', 'id_type' => 'nric', 'id_number' => '900202-10-6666',
            'email' => $stf2->email, 'position' => 'Trainer', 'joined_on' => '2026-01-01', 'classification' => 'contractor'], $owner);
        $staff->addEngagement($siti, ['rate_type' => 'daily', 'rate_amount' => '550.00', 'effective_from' => '2026-01-01', 'title' => 'Trainer agreement v1'], $owner);
        app(ProjectService::class)->addMember($project, ['staff_profile_id' => $sam->id, 'role' => 'trainer', 'from_date' => '2026-01-01'], $owner);
        app(TimesheetService::class)->create($sam, today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString(), $stf1);

        // UAT-02 / UAT-01: Sam's mileage claim → approved by FIN → paid (maker-checker). Siti must not see it.
        $claims = app(ClaimService::class);
        $claim = $claims->create($stf1, ['purpose' => 'Cohort 1 delivery travel', 'project_id' => $project->id, 'lines' => [['claim_type_id' => (int) ClaimType::query()->where('code', 'mileage')->value('id'),
            'incurred_on' => today()->toDateString(), 'description' => 'KL → Shah Alam return', 'origin' => 'Kuala Lumpur', 'destination' => 'Shah Alam', 'distance_km' => '100']]], $stf1);
        $claims->submit($claim, $stf1);
        $claims->approve($claim->fresh(), $fin1);
        $pay = app(PaymentService::class);
        $p = $pay->create(['payment_date' => today()->toDateString(), 'bank_account_id' => $bank->id, 'method' => 'transfer', 'reference' => 'DuitNow claim',
            'payee_type' => 'staff', 'payee_id' => $stf1->id, 'payee_name' => $stf1->name, 'allocations' => [['target_type' => 'claim', 'target_id' => $claim->id, 'amount' => (string) $claim->fresh()->approved_amount?->getAmount()]]], $fin1);
        $pay->submit($p, $fin1);
        $pay->approve($p->fresh(), $fin2);
        $pay->post($p->fresh(), $fin1);

        // UAT-03: laptop bought on the company account → bill → payment → asset registered from the purchase.
        $vendor = Vendor::query()->create(['company_id' => Company::current()->id, 'name' => 'Demo: Tech Supplies Sdn Bhd', 'status' => 'active']);
        $bills = app(BillService::class);
        $bill = $bills->create(['vendor_id' => $vendor->id, 'vendor_invoice_no' => 'TS-88123', 'bill_date' => today()->toDateString(), 'due_date' => today()->addDays(30)->toDateString(),
            'lines' => [['description' => 'Dell Latitude 7450 laptop', 'quantity' => '1', 'unit_price' => '4200.00', 'capitalization_class' => 'CAPITAL_ASSET']]], $fin1);
        $bills->submit($bill, $fin1);
        $bill = $bills->approve($bill->fresh(), $owner);
        $bp = $pay->create(['payment_date' => today()->toDateString(), 'bank_account_id' => $bank->id, 'method' => 'transfer', 'payee_type' => 'vendor', 'payee_id' => $vendor->id, 'payee_name' => $vendor->name,
            'allocations' => [['target_type' => 'bill', 'target_id' => $bill->id, 'amount' => '4200.00']]], $fin1);
        $pay->submit($bp, $fin1);
        $pay->approve($bp->fresh(), $owner);
        $pay->post($bp->fresh(), $fin1);
        // (the asset candidate is recorded after commit — registered in registerDemoAsset())

        // UAT-11: a payment keyed by Farah waits for a different approver (she cannot approve her own).
        $toner = $bills->create(['vendor_id' => $vendor->id, 'vendor_invoice_no' => 'TS-88190', 'bill_date' => today()->toDateString(), 'due_date' => today()->addDays(14)->toDateString(),
            'lines' => [['description' => 'Projector bulbs', 'quantity' => '2', 'unit_price' => '310.00', 'capitalization_class' => 'EXPENSE']]], $fin1);
        $bills->submit($toner, $fin1);
        $toner = $bills->approve($toner->fresh(), $owner);
        $pending = $pay->create(['payment_date' => today()->toDateString(), 'bank_account_id' => $bank->id, 'method' => 'transfer', 'payee_type' => 'vendor', 'payee_id' => $vendor->id, 'payee_name' => $vendor->name,
            'allocations' => [['target_type' => 'bill', 'target_id' => $toner->id, 'amount' => '620.00']]], $fin1);
        $pay->submit($pending, $fin1);

        // UAT-09: a posted expense in January; January is then closed and locked.
        $tx = app(TransactionService::class);
        $jan = $tx->create(['direction' => 'outflow', 'payment_source' => 'DIRECT_COMPANY', 'capitalization_class' => 'EXPENSE', 'transaction_date' => '2026-01-15', 'bank_account_id' => $bank->id,
            'description' => 'Demo: printer toner (January)', 'lines' => [['description' => 'Toner cartridges', 'amount' => '380.00', 'category_id' => (int) DictionaryItem::query()->whereRelation('dictionary', 'code', 'expense_category')->where('code', 'office')->value('id')]]], $fin1);
        $tx->post($jan, $fin1);
        $period = FiscalPeriod::query()->where('starts_on', '<=', '2026-01-15')->where('ends_on', '>=', '2026-01-15')->first();
        if ($period !== null && $period->locked_at === null) {
            app(FiscalPeriodService::class)->lock($period, $owner, 'January 2026 closed (demo)');
        }

        // UAT-12: subscription renewal is a scheduled event only — no finance transaction until a bill is recorded.
        app(SubscriptionService::class)->save(null, ['vendor_id' => $vendor->id, 'product' => 'Demo: Microsoft 365 Business Standard', 'seats' => 5, 'price' => '1950.00', 'currency' => 'MYR',
            'billing_cycle' => 'annual', 'renewal_date' => today()->addDays(25)->toDateString(), 'owner_id' => $owner->id], $owner);

        // UAT-08: credential metadata only — the secret lives in the vault.
        app(CredentialService::class)->save(null, ['system_name' => 'Demo: Xero (company)', 'category' => 'corporate', 'environment' => 'production', 'owner_id' => $owner->id,
            'vault_provider' => 'bitwarden', 'vault_item_reference' => '5d3c7e11-aaaa-4bbb-8ccc-000000000001', 'sensitivity' => 'critical', 'rotation_interval_days' => 90], $owner);
    }

    /** UAT-03: the laptop bill's asset candidate (recorded by an after-commit listener) becomes an asset. */
    private function registerDemoAsset(): void
    {
        $billId = DB::table('bills')->where('vendor_invoice_no', 'TS-88123')->value('id');
        $candidate = AssetCandidate::query()->where('source_type', 'bill')->where('source_id', $billId)->where('status', 'pending')->first();
        if ($candidate === null) {
            return;
        }
        $owner = User::query()->where('email', config('infernix.seed_owner.email'))->firstOrFail();
        app(AssetService::class)->registerFromCandidate($candidate, ['name' => 'Dell Latitude 7450', 'category_id' => AssetCategory::query()->where('name', 'Computers & laptops')->value('id'),
            'serial_no' => 'DL7450-0001'], $owner);
    }

    private function approve(CommercialDocument $doc, User $level1, User $owner): void
    {
        $svc = app(CommercialDocumentService::class);
        for ($i = 0; $i < 5; $i++) {
            $fresh = $doc::query()->findOrFail($doc->id);
            if ($fresh->status !== DocumentStatus::PendingApproval) {
                return;
            }
            $svc->approve($fresh, $fresh->current_level === 1 ? $level1 : $owner);
        }
    }

    private function user(string $email, string $name, string $role): User
    {
        /** @var User $user */
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => (string) config('infernix.seed_demo_password'),
            'company_id' => Company::current()->id, 'email_verified_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('code', $role)->value('id') => ['granted_at' => now()]]);
        if ($user->fresh(['roles'])?->mfaRequired()) {
            User::withoutEvents(fn () => $user->forceFill(['mfa_secret' => self::DEV_MFA_SECRET, 'mfa_confirmed_at' => now()])->save());
        }

        return $user;
    }
}
