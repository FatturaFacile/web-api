<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const DELIVERY_NOTE_NUMBERING_WORKERS = 6;

/**
 * Starts one worker mode. The workers commit on their own connections, pointed at the database of this test process.
 *
 * @return array{0: resource, 1: array<int, resource>}
 */
function deliveryNoteNumberingWorker(string ...$arguments): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', __DIR__.'/delivery_note_numbering_worker.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), [
        ...getenv(),
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
    ]);

    return [$process, $pipes];
}

/**
 * @param  array{0: resource, 1: array<int, resource>}  $worker
 * @return array{0: string, 1: string}
 */
function deliveryNoteNumberingOutput(array $worker): array
{
    [$process, $pipes] = $worker;
    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    proc_close($process);

    return [trim($output), trim($error)];
}

beforeEach(function (): void {
    [$output, $error] = deliveryNoteNumberingOutput(deliveryNoteNumberingWorker('setup', (string) DELIVERY_NOTE_NUMBERING_WORKERS));

    expect($error)->toBe('');

    $this->fixtureFile = (string) tempnam(sys_get_temp_dir(), 'ddt');
    file_put_contents($this->fixtureFile, $output);
});

afterEach(function (): void {
    deliveryNoteNumberingOutput(deliveryNoteNumberingWorker('cleanup', $this->fixtureFile));
    unlink($this->fixtureFile);
});

test('delivery notes created at the same moment by the module and by the sales API take distinct consecutive numbers', function (): void {
    $startAt = (string) (microtime(true) + 12);
    $workers = [];

    for ($index = 0; $index < DELIVERY_NOTE_NUMBERING_WORKERS; $index++) {
        $workers[] = deliveryNoteNumberingWorker('deliver', $this->fixtureFile, (string) $index, $startAt);
    }

    $numbers = [];

    foreach ($workers as $worker) {
        [$output, $error] = deliveryNoteNumberingOutput($worker);

        expect($error)->toBe('');
        $numbers[] = $output;
    }

    sort($numbers);
    $year = substr($numbers[0], 4, 4);
    $expected = array_map(static fn (int $sequence): string => sprintf('DDT-%s-%04d', $year, $sequence), range(1, DELIVERY_NOTE_NUMBERING_WORKERS));

    expect($numbers)->toBe($expected);
});
