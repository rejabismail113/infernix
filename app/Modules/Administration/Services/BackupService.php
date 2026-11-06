<?php

declare(strict_types=1);

namespace App\Modules\Administration\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Nightly encrypted backup (NFR-06): pg_dump (custom format) → AES-256 (openssl, PBKDF2, passphrase from the
 * environment only) → private object storage, SHA-256 recorded. Retention: every backup for 14 days, Sunday
 * backups for 8 weeks, first-of-month for 12 months (PLACEHOLDER, A-86). Restore test: newest good backup is
 * downloaded, decrypted, restored into a scratch database and sanity-checked (balanced journals, audit trail,
 * integrity triggers), then dropped — proving RPO ≤ 24 h and measuring RTO.
 *
 * @see SRS M12-06, NFR-06
 */
final class BackupService
{
    public const SCRATCH_DB = 'infernix_restore_check';

    public function __construct(private readonly NotificationService $notifications) {}

    public function run(?User $actor = null): int
    {
        $runId = (int) DB::table('backup_runs')->insertGetId(['kind' => 'backup', 'status' => 'running', 'started_at' => now(), 'triggered_by' => $actor?->id]);
        $dump = tempnam(sys_get_temp_dir(), 'bk');
        $enc = $dump.'.enc';
        try {
            $this->process(array_merge(['pg_dump', '--format=custom', '--no-owner', '--no-acl', '--file='.$dump], $this->connArgs(), [$this->db()]));
            $this->process(['openssl', 'enc', '-aes-256-cbc', '-pbkdf2', '-iter', '200000', '-salt', '-in', $dump, '-out', $enc, '-pass', 'env:BACKUP_PASSPHRASE'], ['BACKUP_PASSPHRASE' => $this->passphrase()]);
            $key = sprintf('%s/%s.dump.enc', now()->format('Y/m'), 'infernix-'.now()->format('Ymd-His'));
            $stream = fopen($enc, 'rb') ?: throw new RuntimeException('Cannot read encrypted dump.');
            Storage::disk($this->disk())->put($key, $stream);
            $size = (int) filesize($enc);
            $sha = (string) hash_file('sha256', $enc);
            DB::table('backup_runs')->where('id', $runId)->update(['status' => 'ok', 'finished_at' => now(), 'object_key' => $key, 'size_bytes' => $size, 'sha256' => $sha,
                'details' => json_encode(['database' => $this->db(), 'tables' => $this->tableCount()])]);
            Audit::log('backup', 'backup_run', $runId, null, ['object_key' => $key, 'size' => $size, 'sha256' => $sha], null, $actor);
            $this->prune();
        } catch (Throwable $e) {
            $this->fail($runId, $e);
        } finally {
            @unlink($dump);
            @unlink($enc);
        }

        return $runId;
    }

    /** Restore the newest good backup into a scratch DB and sanity-check it. */
    public function restoreTest(?User $actor = null): int
    {
        $source = DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->where('pruned', false)->orderByDesc('started_at')->first();
        $runId = (int) DB::table('backup_runs')->insertGetId(['kind' => 'restore_test', 'status' => 'running', 'started_at' => now(), 'source_run_id' => $source->id ?? null, 'triggered_by' => $actor?->id]);
        $enc = tempnam(sys_get_temp_dir(), 'rs');
        $dump = $enc.'.dump';
        $t0 = microtime(true);
        try {
            if ($source === null) {
                throw new RuntimeException('No successful backup to restore.');
            }
            file_put_contents($enc, Storage::disk($this->disk())->get((string) $source->object_key) ?? throw new RuntimeException('Backup object missing.'));
            if (! hash_equals((string) $source->sha256, (string) hash_file('sha256', $enc))) {
                throw new RuntimeException('Checksum mismatch: the backup object was altered or corrupted.');
            }
            $this->process(['openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-iter', '200000', '-in', $enc, '-out', $dump, '-pass', 'env:BACKUP_PASSPHRASE'], ['BACKUP_PASSPHRASE' => $this->passphrase()]);
            $this->process(array_merge(['dropdb', '--if-exists'], $this->connArgs(), [self::SCRATCH_DB]));
            $this->process(array_merge(['createdb'], $this->connArgs(), [self::SCRATCH_DB]));
            $this->process(array_merge(['pg_restore', '--no-owner', '--no-acl', '--exit-on-error', '--dbname='.self::SCRATCH_DB], $this->connArgs(), [$dump]));
            $checks = $this->sanity();
            $this->process(array_merge(['dropdb', '--if-exists'], $this->connArgs(), [self::SCRATCH_DB]));
            $failed = array_keys(array_filter($checks, fn ($c) => ($c['ok'] ?? false) === false));
            if ($failed !== []) {
                throw new RuntimeException('Restored database failed checks: '.implode(', ', $failed));
            }
            $rto = (int) round(microtime(true) - $t0);
            $rpoHours = Carbon::parse((string) $source->started_at)->diffInHours(now());
            DB::table('backup_runs')->where('id', $runId)->update(['status' => 'ok', 'finished_at' => now(), 'details' => json_encode(['checks' => $checks, 'restore_seconds' => $rto, 'backup_age_hours' => (int) $rpoHours])]);
            Audit::log('restore_test', 'backup_run', $runId, null, ['source' => $source->id, 'restore_seconds' => $rto], null, $actor);
        } catch (Throwable $e) {
            $this->fail($runId, $e);
            try {
                $this->process(array_merge(['dropdb', '--if-exists'], $this->connArgs(), [self::SCRATCH_DB]));
            } catch (Throwable) {
            }
        } finally {
            @unlink($enc);
            @unlink($dump);
        }

        return $runId;
    }

    /** Retention: keep 14 daily, 8 weekly (Sunday), 12 monthly (1st). @return int objects deleted */
    public function prune(?Carbon $now = null): int
    {
        $now ??= now();
        $deleted = 0;
        foreach (DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->where('pruned', false)->get() as $r) {
            $at = Carbon::parse((string) $r->started_at);
            $age = (int) $at->diffInDays($now);
            $keep = $age <= 14 || ($at->isSunday() && $age <= 7 * 8) || ($at->day === 1 && $age <= 366);
            if (! $keep) {
                Storage::disk($this->disk())->delete((string) $r->object_key);
                DB::table('backup_runs')->where('id', $r->id)->update(['pruned' => true]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return array{last_backup: object|null, last_restore_test: object|null, rpo_ok: bool, restore_test_ok: bool} */
    public function status(): array
    {
        $last = DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->orderByDesc('started_at')->first();
        $test = DB::table('backup_runs')->where('kind', 'restore_test')->where('status', 'ok')->orderByDesc('started_at')->first();

        return ['last_backup' => $last, 'last_restore_test' => $test,
            'rpo_ok' => $last !== null && Carbon::parse((string) $last->started_at)->gt(now()->subHours(26)),
            'restore_test_ok' => $test !== null && Carbon::parse((string) $test->started_at)->gt(now()->subDays(92))];
    }

    /** @return array<string, array{ok: bool, value: mixed}> */
    private function sanity(): array
    {
        $q = function (string $sql): string {
            $p = $this->process(array_merge(['psql', '-tA', '-v', 'ON_ERROR_STOP=1', '-c', $sql], $this->connArgs(), [self::SCRATCH_DB]));

            return trim($p->getOutput());
        };
        $unbalanced = (int) $q('SELECT count(*) FROM (SELECT journal_id FROM journal_lines GROUP BY journal_id HAVING sum(debit) <> sum(credit)) x');
        $migrations = (int) $q('SELECT count(*) FROM migrations');
        $triggers = (int) $q("SELECT count(*) FROM pg_trigger WHERE tgname IN ('journals_immutable','audit_events_append_only','journal_lines_immutable')");
        $users = (int) $q('SELECT count(*) FROM users');

        return ['journals_balanced' => ['ok' => $unbalanced === 0, 'value' => $unbalanced], 'migrations_present' => ['ok' => $migrations > 0, 'value' => $migrations],
            'integrity_triggers' => ['ok' => $triggers === 3, 'value' => $triggers], 'users_present' => ['ok' => $users > 0, 'value' => $users]];
    }

    private function fail(int $runId, Throwable $e): void
    {
        $msg = mb_substr(str_replace($this->passphrase(false), '[redacted]', $e->getMessage()), 0, 1000);
        DB::table('backup_runs')->where('id', $runId)->update(['status' => 'failed', 'finished_at' => now(), 'error' => $msg]);
        $this->notifications->notify('BackupFailed', 0, "backup:{$runId}", 'Backup job failed', 'A backup or restore test failed: '.mb_substr($msg, 0, 200), route('admin.backups.index'));
    }

    /**
     * @param  list<string>  $cmd
     * @param  array<string, string>  $env
     */
    private function process(array $cmd, array $env = []): Process
    {
        $c = config('database.connections.pgsql');
        $p = new Process($cmd, null, $env + ['PGPASSWORD' => (string) ($c['password'] ?? '')], null, 3600);
        $p->mustRun();

        return $p;
    }

    /** @return list<string> */
    private function connArgs(): array
    {
        $c = config('database.connections.pgsql');

        return ['--host='.$c['host'], '--port='.$c['port'], '--username='.$c['username']];
    }

    private function db(): string
    {
        return (string) config('database.connections.pgsql.database');
    }

    private function disk(): string
    {
        return (string) config('infernix.backup.disk');
    }

    private function tableCount(): int
    {
        return (int) DB::scalar("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'");
    }

    private function passphrase(bool $required = true): string
    {
        $p = (string) config('infernix.backup.passphrase');
        if ($required && mb_strlen($p) < 16) {
            throw new RuntimeException('BACKUP_PASSPHRASE is not set (min 16 characters); refusing to write an unencrypted backup.');
        }

        return $p === '' ? "\0no-passphrase\0" : $p;
    }
}
