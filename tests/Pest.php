<?php

declare(strict_types=1);

use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Kernel\Identity\Models\User;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Services\DocumentService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
    ]);
}
