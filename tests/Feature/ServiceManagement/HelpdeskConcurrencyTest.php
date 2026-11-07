<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const HELPDESK_CONCURRENT_WORKERS = 6;

/**
 * Starts the workers at once and returns what each one got.
 *
 * @return list<string>
 */
function helpdeskRunConcurrently(string $mode, string $fixtureFile): array
{
    $script = __DIR__.'/helpdesk_concurrency_worker.php';
    $startAt = microtime(true) + 12;
    $processes = [];

    for ($index = 0; $index < HELPDESK_CONCURRENT_WORKERS; $index++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', $script, $mode, $fixtureFile, (string) $index, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), helpdeskWorkerEnvironment());
        $processes[] = [$process, $pipes];
    }

    $outcomes = [];

    foreach ($processes as [$process, $pipes]) {
        $outcomes[] = trim((string) stream_get_contents($pipes[1]));
        $error = trim((string) stream_get_contents($pipes[2]));
        proc_close($process);

        expect($error)->toBe('');
    }

    return $outcomes;
}

/**
 * The environment of a worker: the one of this process, pointed at the database this test process uses.
 *
 * @return array<string, string>
 */
function helpdeskWorkerEnvironment(): array
{
    return [
        ...getenv(),
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
    ];
}

function helpdeskWorkerCommand(string ...$arguments): string
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', __DIR__.'/helpdesk_concurrency_worker.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), helpdeskWorkerEnvironment());
    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    proc_close($process);

    expect(trim($error))->toBe('');

    return $output;
}

/**
 * Reads committed rows from a session of its own: the session of the test sits in a transaction of its own.
 */
function helpdeskCommitted(): Connection
{
    config(['database.connections.helpdesk_committed' => config('database.connections.'.config('database.default'))]);

    return DB::connection('helpdesk_committed');
}

beforeEach(function (): void {
    $this->fixtureFile = tempnam(sys_get_temp_dir(), 'helpdesk');
    file_put_contents($this->fixtureFile, helpdeskWorkerCommand('setup', (string) HELPDESK_CONCURRENT_WORKERS));
    $this->fixture = json_decode((string) file_get_contents($this->fixtureFile), true);
});

afterEach(function (): void {
    helpdeskWorkerCommand('cleanup', $this->fixtureFile);
    unlink($this->fixtureFile);
    DB::purge('helpdesk_committed');
});

test('concurrent round robin assignments in one team share the tickets evenly among the agents', function (): void {
    $outcomes = helpdeskRunConcurrently('assign', $this->fixtureFile);
    $counts = array_count_values($outcomes);
    ksort($counts);

    $expected = array_fill_keys($this->fixture['agents'], HELPDESK_CONCURRENT_WORKERS / 2);
    ksort($expected);

    expect($counts)->toBe($expected)
        ->and(helpdeskCommitted()->table('esm_tickets')->whereIn('id', $this->fixture['tickets'])->whereNull('assigned_technician_id')->count())->toBe(0)
        ->and(helpdeskCommitted()->table('esm_ticket_activities')->whereIn('ticket_id', $this->fixture['tickets'])->where('type', 'assigned')->count())->toBe(HELPDESK_CONCURRENT_WORKERS);
});

test('of concurrent merges of one ticket into different targets exactly one stands', function (): void {
    $counts = array_count_values(helpdeskRunConcurrently('merge', $this->fixtureFile));
    $source = helpdeskCommitted()->table('esm_tickets')->where('id', $this->fixture['source'])->first();

    expect($counts['merged'] ?? 0)->toBe(1)
        ->and($counts['refused'] ?? 0)->toBe(HELPDESK_CONCURRENT_WORKERS - 1)
        ->and($source->merged_into_ticket_id)->toBeIn($this->fixture['tickets'])
        ->and(helpdeskCommitted()->table('esm_ticket_activities')->where('ticket_id', $this->fixture['source'])->where('type', 'merged_into')->count())->toBe(1);
});
