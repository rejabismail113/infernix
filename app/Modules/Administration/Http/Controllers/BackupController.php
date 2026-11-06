<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Administration\Jobs\RunBackup;
use App\Modules\Administration\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backup Status (M12-06): last backups, restore tests, RPO / restore-test freshness; Owner can trigger a run.
 *
 * @see SRS M12-06, NFR-06
 */
final class BackupController
{
    public function index(Request $request, PermissionService $permissions, BackupService $backups): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($permissions->can($user, PermissionAction::View, 'backup_status'), 403);
        $status = $backups->status();

        return Inertia::render('Admin/Backups', [
            'runs' => DB::table('backup_runs')->orderByDesc('started_at')->limit(60)->get()->map(fn ($r): array => ['id' => (int) $r->id, 'kind' => $r->kind, 'status' => $r->status, 'started_at' => (string) $r->started_at,
                'finished_at' => $r->finished_at === null ? null : (string) $r->finished_at, 'object_key' => $r->object_key, 'size_bytes' => $r->size_bytes === null ? null : (int) $r->size_bytes,
                'sha256' => $r->sha256, 'error' => $r->error, 'pruned' => (bool) $r->pruned, 'details' => json_decode((string) ($r->details ?? 'null'), true)]),
            'rpoOk' => $status['rpo_ok'], 'restoreTestOk' => $status['restore_test_ok'],
            'disk' => config('infernix.backup.disk'), 'encrypted' => mb_strlen((string) config('infernix.backup.passphrase')) >= 16,
            'can' => ['run' => $permissions->can($user, PermissionAction::Approve, 'backup_status')],
        ]);
    }

    public function run(Request $request, PermissionService $permissions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($permissions->can($user, PermissionAction::Approve, 'backup_status'), 403);
        $kind = $request->validate(['kind' => ['required', 'in:backup,restore_test']])['kind'];
        RunBackup::dispatch($kind, $user->id);

        return back()->with('success', $kind === 'backup' ? __('Backup started.') : __('Restore test started.'));
    }
}
