<?php

declare(strict_types=1);

use Modules\IndustrialLaundry\Services\TagReadFilter;

/**
 * @return list<array<string, mixed>>
 */
function ilrReads(): array
{
    return [
        ['epc' => 'e280 1160-0000 0000 0000 0001', 'rssi' => -61.0, 'antenna_id' => 'GATE-01-A1'],
        ['epc' => 'E28011600000000000000001', 'rssi' => -55.5, 'antenna_id' => 'GATE-01-A2'],
        ['epc' => '0xE28011600000000000000002', 'rssi' => -70.0],
        ['epc' => 'E28011600000000000000003', 'rssi' => -90.0],
        ['epc' => 'E28011600000000000000004', 'rssi' => -50.0, 'antenna_id' => 'GATE-02-A1'],
        ['epc' => 'ZZ-NOT-HEX'],
        ['epc' => 'E2801'],
        ['epc' => ''],
    ];
}

it('normalises an EPC to upper case hexadecimal without separators and refuses what is not one', function (): void {
    expect(TagReadFilter::normalizeEpc('e280 1160-0000:0000_0000 0001'))->toBe('E28011600000000000000001')
        ->and(TagReadFilter::normalizeEpc('0xe2801160'))->toBe('E2801160')
        ->and(TagReadFilter::normalizeEpc('E2801'))->toBeNull()
        ->and(TagReadFilter::normalizeEpc('E28011G0'))->toBeNull()
        ->and(TagReadFilter::normalizeEpc(str_repeat('AB', 33)))->toBeNull()
        ->and(TagReadFilter::normalizeEpc(''))->toBeNull();
});

it('keeps one accepted tag per EPC with its strongest signal and reports every extra read as a duplicate', function (): void {
    $result = (new TagReadFilter)->filter(ilrReads(), 'GATE-01');

    expect(array_column($result['accepted'], 'epc'))->toBe(['E28011600000000000000001', 'E28011600000000000000002'])
        ->and($result['accepted'][0]['rssi'])->toBe(-55.5)
        ->and($result['accepted'][0]['reads'])->toBe(2)
        ->and($result['accepted'][1]['reads'])->toBe(1);

    $reasons = array_count_values(array_column($result['rejected'], 'reason'));
    ksort($reasons);
    expect($reasons)->toBe([
        TagReadFilter::Duplicate => 1,
        TagReadFilter::ForeignAntenna => 1,
        TagReadFilter::InvalidEpc => 3,
        TagReadFilter::OutOfRange => 1,
    ]);
});

it('rejects the reads weaker than the configured threshold and the ones of another gate', function (): void {
    config(['industrial_laundry.rfid.min_rssi_dbm' => -60]);

    $result = (new TagReadFilter)->filter(ilrReads(), 'GATE-01');

    expect(array_column($result['accepted'], 'epc'))->toBe(['E28011600000000000000001'])
        ->and(collect($result['rejected'])->where('reason', TagReadFilter::OutOfRange)->pluck('epc')->sort()->values()->all())->toBe(['E28011600000000000000001', 'E28011600000000000000002', 'E28011600000000000000003']);
});

it('gives the same answer whatever order the reader sent the reads in', function (): void {
    $reads = ilrReads();
    $first = (new TagReadFilter)->filter($reads, 'GATE-01');

    foreach ([array_reverse($reads), [...array_slice($reads, 4), ...array_slice($reads, 0, 4)]] as $shuffled) {
        $again = (new TagReadFilter)->filter($shuffled, 'GATE-01');

        expect(array_column($again['accepted'], 'epc'))->toBe(array_column($first['accepted'], 'epc'))
            ->and(array_map(static fn (array $read): string => $read['epc'].'|'.$read['reason'], $again['rejected']))->toBe(array_map(static fn (array $read): string => $read['epc'].'|'.$read['reason'], $first['rejected']));
    }
});

it('accepts a read without signal strength and without antenna', function (): void {
    $result = (new TagReadFilter)->filter([['epc' => 'E28011600000000000000009']], 'GATE-01');

    expect($result['accepted'])->toHaveCount(1)
        ->and($result['accepted'][0]['rssi'])->toBeNull()
        ->and($result['rejected'])->toBe([]);
});
