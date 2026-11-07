<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\CourseEnrolmentStatus;
use Modules\Associations\Models\ResourceBooking;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

const ASSOCIATION_CONCURRENT_WORKERS = 6;

/**
 * Starts the workers at once and returns what each one got.
 *
 * @return list<string>
 */
function runConcurrently(string $mode, string $fixtureFile): array
{
    $script = __DIR__.'/concurrency_worker.php';
    $startAt = microtime(true) + 12;
    $processes = [];

    for ($index = 0; $index < ASSOCIATION_CONCURRENT_WORKERS; $index++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', $script, $mode, $fixtureFile, (string) $index, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), workerEnvironment());
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
function workerEnvironment(): array
{
    return [
        ...getenv(),
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
    ];
}

function workerCommand(string ...$arguments): string
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', __DIR__.'/concurrency_worker.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), workerEnvironment());
    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    proc_close($process);

    expect(trim($error))->toBe('');

    return $output;
}

/**
 * Reads committed rows from a session of its own: the session of the test sits in a transaction of its own.
 */
function committed(): Connection
{
    config(['database.connections.association_committed' => config('database.connections.'.config('database.default'))]);

    return DB::connection('association_committed');
}

beforeEach(function (): void {
    $this->fixtureFile = tempnam(sys_get_temp_dir(), 'assoc');
    file_put_contents($this->fixtureFile, workerCommand('setup', (string) ASSOCIATION_CONCURRENT_WORKERS));
    $this->fixture = json_decode((string) file_get_contents($this->fixtureFile), true);
});

afterEach(function (): void {
    workerCommand('cleanup', $this->fixtureFile);
    unlink($this->fixtureFile);
    DB::purge('association_committed');
});

test('the last places of a course go to one request each, never beyond the capacity', function (): void {
    $outcomes = runConcurrently('enrol', $this->fixtureFile);
    $counts = array_count_values($outcomes);
    $course = committed()->table('assoc_courses')->where('id', $this->fixture['course'])->first();

    expect($counts['enrolled'] ?? 0)->toBe(2)
        ->and($counts['refused'] ?? 0)->toBe(ASSOCIATION_CONCURRENT_WORKERS - 2)
        ->and(committed()->table('assoc_course_enrolments')->where('course_id', $this->fixture['course'])->where('status', CourseEnrolmentStatus::Enrolled->value)->count())->toBe(2)
        ->and($course->registrations_open)->toBeFalse();
});

test('of overlapping bookings made at the same moment exactly one stands', function (): void {
    $counts = array_count_values(runConcurrently('book', $this->fixtureFile));

    expect($counts[ResourceBooking::Confirmed] ?? 0)->toBe(1)
        ->and($counts['refused'] ?? 0)->toBe(ASSOCIATION_CONCURRENT_WORKERS - 1)
        ->and(committed()->table('assoc_resource_bookings')->where('resource_id', $this->fixture['resource'])->count())->toBe(1);
});

test('a reader never lets more people in than the area holds', function (): void {
    $outcomes = runConcurrently('enter', $this->fixtureFile);
    $counts = array_count_values($outcomes);

    expect($counts['granted'] ?? 0)->toBe(2)
        ->and($counts['area_full'] ?? 0)->toBe(ASSOCIATION_CONCURRENT_WORKERS - 2)
        ->and(committed()->table('assoc_area_presences')->where('area_id', $this->fixture['area'])->whereNull('left_at')->count())->toBe(2)
        ->and(committed()->table('assoc_access_logs')->where('area_id', $this->fixture['area'])->count())->toBe(ASSOCIATION_CONCURRENT_WORKERS);
});
