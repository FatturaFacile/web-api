<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const ENASARCO_CEILING_WORKERS = 4;

/**
 * Runs one worker mode and returns what it printed. The workers commit on their own connections, pointed at the
 * database of this test process.
 *
 * @return array{0: string, 1: string}
 */
function enasarcoWorker(string ...$arguments): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', __DIR__.'/enasarco_ceiling_worker.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), [
        ...getenv(),
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
    ]);
    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    proc_close($process);

    return [trim($output), trim($error)];
}

beforeEach(function (): void {
    [$output, $error] = enasarcoWorker('setup', (string) ENASARCO_CEILING_WORKERS);

    expect($error)->toBe('');

    $this->fixtureFile = (string) tempnam(sys_get_temp_dir(), 'enasarco');
    file_put_contents($this->fixtureFile, $output);
    $this->fixture = json_decode($output, true);
});

afterEach(function (): void {
    enasarcoWorker('cleanup', $this->fixtureFile);
    unlink($this->fixtureFile);
});

test('invoices issued at the same moment never take more than the annual ceiling', function (): void {
    $startAt = (string) (microtime(true) + 12);
    $processes = [];

    for ($index = 0; $index < ENASARCO_CEILING_WORKERS; $index++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', __DIR__.'/enasarco_ceiling_worker.php', 'issue', $this->fixtureFile, (string) $index, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), [
            ...getenv(),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
        ]);
        $processes[] = [$process, $pipes];
    }

    $bases = [];

    foreach ($processes as [$process, $pipes]) {
        $bases[] = trim((string) stream_get_contents($pipes[1]));
        $error = trim((string) stream_get_contents($pipes[2]));
        proc_close($process);

        expect($error)->toBe('');
    }

    sort($bases);
    $committed = (string) DB::connection()->getPdo()->query("SELECT COALESCE(SUM(enasarco_base), 0) FROM invoices WHERE tenant_id = '{$this->fixture['tenant']}' AND status = 'posted'")->fetchColumn();

    expect($bases)->toBe(['0.00', '0.00', '500.00', '1000.00'])
        ->and(bccomp($committed, '1500', 2))->toBe(0);
});
