<?php

declare(strict_types=1);

use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Administration\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * 50 OS processes allocate from the same sequence at the same time. The row lock must serialise them:
 * no duplicates, no gaps, next_number == start + 50.
 *
 * @see SRS §6.3, G-03, M03-08 (gap-free numbering)
 */
it('allocates 50 numbers in parallel without gaps or duplicates', function (): void {
    $companyId = Company::current()->id;
    $start = DB::table('numbering_sequences')->where('document_type', 'PAYMENT_VOUCHER')->where('fiscal_year', 2026)->value('next_number');
    $dir = sys_get_temp_dir().'/infernix-numbering-'.uniqid();
    mkdir($dir);

    $pids = [];
    for ($i = 0; $i < 50; $i++) {
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('fork failed');
        }
        if ($pid === 0) {
            // Child: fresh connection, allocate inside a short transaction, write result, hard-exit.
            try {
                DB::purge();
                DB::reconnect();
                $n = DB::transaction(fn () => app(NumberingService::class)->allocate($companyId, 'PAYMENT_VOUCHER', 2026))['formatted'];
                file_put_contents("{$dir}/{$i}", $n);
            } catch (Throwable $e) {
                file_put_contents("{$dir}/{$i}.err", $e->getMessage());
            }
            posix_kill(posix_getpid(), SIGKILL);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    DB::purge();
    DB::reconnect();

    $errors = glob("{$dir}/*.err") ?: [];
    expect($errors)->toBe([], 'child errors: '.implode('; ', array_map(fn ($f) => file_get_contents($f), $errors)));
    $numbers = array_map(fn ($f) => (string) file_get_contents($f), glob("{$dir}/[0-9]*") ?: []);
    sort($numbers);
    $expected = array_map(fn ($n) => sprintf('PV-2026-%04d', $n), range($start, $start + 49));

    expect($numbers)->toHaveCount(50)
        ->and(array_unique($numbers))->toHaveCount(50)
        ->and($numbers)->toBe($expected)
        ->and(DB::table('numbering_sequences')->where('document_type', 'PAYMENT_VOUCHER')->where('fiscal_year', 2026)->value('next_number'))->toBe($start + 50);
    array_map('unlink', glob("{$dir}/*") ?: []);
    rmdir($dir);
})->group('§6.3', 'G-03', 'M03-08');

it('refuses to allocate outside a transaction', function (): void {
    expect(fn () => app(NumberingService::class)->allocate(Company::current()->id, 'INVOICE', 2026))
        ->toThrow(RuntimeException::class, 'inside the document-creating transaction');
})->group('§6.3');
