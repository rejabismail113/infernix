<?php

declare(strict_types=1);

use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Administration\Models\DictionaryItem;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Services\DocumentService;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\ChartAccount;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Services\TransactionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\Files;
use Tests\TestCase;

/*
 | Requirement traceability: every test carries ->group('<REQ-ID>') tags (e.g. 'G-01', 'M01-06',
 | 'UAT-05'). docs/TRACEABILITY.md maps IDs to test files. See CLAUDE.md.
 |
 | Feature tests run inside a transaction (RefreshDatabase) on the seeded PostgreSQL database.
 | Concurrency tests need COMMITTED rows visible to forked processes, so they use DatabaseTruncation.
 */

pest()->extend(TestCase::class)->in('Unit', 'Architecture');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Concurrency');

/**
 * Store a PDF through the real DocumentService (the code path every module uses).
 *
 * @param  array{title?: string, classification?: string, retention_rule?: string, tags?: list<string>}  $meta
 */
function storeDocument(User $owner, array $meta = [], ?LinkTarget $link = null, ?UploadedFile $file = null): Document
{
    $stored = app(DocumentService::class)->attach($file ?? Files::pdf(), $owner, $meta, $link);

    return Document::query()->findOrFail($stored->documentId);
}

/** The seeded company bank account (finance view). */
function bank(): BankAccount
{
    return BankAccount::query()->with(['companyAccount', 'glAccount'])->firstOrFail();
}

function account(string $systemKeyOrCode): ChartAccount
{
    return ChartAccount::query()->where('system_key', $systemKeyOrCode)->orWhere('code', $systemKeyOrCode)->firstOrFail();
}

function categoryId(string $code): int
{
    return (int) DictionaryItem::query()->whereRelation('dictionary', 'code', 'expense_category')->where('code', $code)->value('id');
}

/** Net debit − credit on an account across posted/reversed journals, as a decimal string. */
function balanceOf(string $systemKeyOrCode): string
{
    $id = account($systemKeyOrCode)->id;
    $r = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ->where('account_id', $id)->where('journals.status', '!=', 'draft')->selectRaw('COALESCE(SUM(debit),0)-COALESCE(SUM(credit),0) AS b')->first();

    return (string) Money::fromDb((string) $r->b)->getAmount();
}

/**
 * Create and post an expense/income register entry through the real service.
 *
 * @param  array<string, mixed>  $overrides
 */
function postedTransaction(array $overrides = [], ?User $actor = null): Transaction
{
    $actor ??= ownerUser();
    $svc = app(TransactionService::class);
    $t = $svc->create(array_merge([
        'direction' => 'outflow', 'payment_source' => 'DIRECT_COMPANY', 'capitalization_class' => 'EXPENSE', 'transaction_date' => today()->toDateString(),
        'bank_account_id' => bank()->id, 'description' => 'Test expense', 'lines' => [['description' => 'Printer paper', 'amount' => '100.00', 'category_id' => categoryId('office')]],
    ], $overrides), $actor);

    return $svc->post($t, $actor);
}

/** Assert the session has a validation error for $key whose message contains $needle. */
function expectSessionErrorContains(string $key, string $needle): void
{
    /** @var ViewErrorBag|null $bag */
    $bag = session('errors');
    expect($bag?->first($key) ?? '')->toContain($needle);
}

/** The seeded Owner (OWN role). */
function ownerUser(): User
{
    return User::query()->where('email', config('infernix.seed_owner.email'))->firstOrFail();
}

/** A fresh user holding the given role codes (roles are seeded). */
function userWithRoles(string ...$codes): User
{
    return User::factory()->withRoles(...$codes)->create()->fresh(['roles']);
}

/**
 * Act as a user with MFA + recent-auth session gates already satisfied, so tests exercise
 * authorization rather than the login ceremony (which has its own tests).
 */
function actingAsSatisfied(User $user): TestCase
{
    /** @var TestCase $tc */
    $tc = test();
    if ($user->mfaRequired() && ! $user->mfaEnabled()) {
        // Roles that require MFA (OWN/FIN) are enrolled directly; MfaTest covers the enrolment ceremony itself.
        User::withoutEvents(fn () => $user->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save());
    }

    return $tc->actingAs($user)->withSession([
        EnsureMfa::SESSION_KEY => now()->toIso8601String(),
        RequireRecentAuth::SESSION_KEY => now()->toIso8601String(),
        RequireRecentAuth::MFA_SESSION_KEY => now()->toIso8601String(),
    ]);
}
