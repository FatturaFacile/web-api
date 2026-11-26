<?php

declare(strict_types=1);

use Modules\AgentCommissions\Services\FiscalEngine;
use Modules\AgentCommissions\Services\TieredRateCalculator;

$tiers = [
    ['from' => '0', 'to' => '10000', 'rate' => '2'],
    ['from' => '10000', 'to' => '50000', 'rate' => '3'],
    ['from' => '50000', 'to' => null, 'rate' => '5'],
];

it('earns the rate of a tier only on the part of the base that falls inside it', function () use ($tiers): void {
    expect(TieredRateCalculator::calculate('4000', '0', $tiers))->toBe(['commission' => '80.00', 'effective_rate' => '2.0000'])
        ->and(TieredRateCalculator::calculate('4000', '8000', $tiers)['commission'])->toBe('100.00') // 2000 at 2% + 2000 at 3%
        ->and(TieredRateCalculator::calculate('4000', '48000', $tiers)['commission'])->toBe('160.00') // 2000 at 3% + 2000 at 5%
        ->and(TieredRateCalculator::calculate('4000', '60000', $tiers)['commission'])->toBe('200.00');
});

it('gives no commission for a zero base or an empty tier table', function () use ($tiers): void {
    expect(TieredRateCalculator::calculate('0', '100', $tiers)['commission'])->toBe('0.00')
        ->and(TieredRateCalculator::calculate('500', '0', [])['commission'])->toBe('0.00');
});

it('accepts only a tier table that starts at zero and has no gap or overlap', function () use ($tiers): void {
    expect(TieredRateCalculator::isContiguous($tiers))->toBeTrue()
        ->and(TieredRateCalculator::isContiguous([]))->toBeFalse()
        ->and(TieredRateCalculator::isContiguous([['from' => '100', 'to' => null, 'rate' => '1']]))->toBeFalse()
        ->and(TieredRateCalculator::isContiguous([['from' => '0', 'to' => '10', 'rate' => '1'], ['from' => '20', 'to' => null, 'rate' => '1']]))->toBeFalse()
        ->and(TieredRateCalculator::isContiguous([['from' => '0', 'to' => null, 'rate' => '1'], ['from' => '10', 'to' => null, 'rate' => '1']]))->toBeFalse()
        ->and(TieredRateCalculator::isContiguous([['from' => '0', 'to' => '0', 'rate' => '1']]))->toBeFalse();
});

it('accrues FIRR progressively by bracket on the yearly commission', function (): void {
    $brackets = [['up_to' => '12500', 'rate' => '4'], ['up_to' => '20000', 'rate' => '2'], ['up_to' => '32000', 'rate' => '1'], ['up_to' => null, 'rate' => '0']];

    expect(FiscalEngine::firrOn('0', $brackets))->toBe('0.00')
        ->and(FiscalEngine::firrOn('10000', $brackets))->toBe('400.00')
        ->and(FiscalEngine::firrOn('15000', $brackets))->toBe('550.00')
        ->and(FiscalEngine::firrOn('40000', $brackets))->toBe('770.00')
        ->and(FiscalEngine::firrOn('1000', []))->toBe('0.00');
});

it('computes the net payable as commission plus VAT less the agent Enasarco share and the withholding', function (): void {
    expect(FiscalEngine::netPayable('800.00', ['vat' => '176.00', 'enasarco_agent' => '70.00', 'withholding' => '83.95']))->toBe('822.05')
        ->and(FiscalEngine::netPayable('-50.00', ['vat' => '-11.00', 'enasarco_agent' => '-4.38', 'withholding' => '-5.00']))->toBe('-51.62');
});
