<?php

declare(strict_types=1);

use App\Domain\Privacy\Erasure\ErasureStrategyResolver;
use App\Domain\Privacy\Erasure\FiscalLockReport;
use Carbon\CarbonImmutable;

test('the jurisdiction decides which retention law the refusal cites', function (): void {
    $report = new FiscalLockReport([['table' => 'invoices', 'module' => 'MOD_026_INVOICES', 'rows' => 1, 'retention_ends_at' => '2034-12-31']], CarbonImmutable::parse('2034-12-31'));
    $resolver = new ErasureStrategyResolver;

    $swiss = $resolver->forCountry('CH')->refusalJustification($report);
    expect($swiss)->toContain('958f')->toContain('nLPD')->not->toContain('2220');

    $italian = $resolver->forCountry('it')->refusalJustification($report);
    expect($italian)->toContain('17(3)(b) GDPR')->toContain('2220')->not->toContain('958f');

    $dual = $resolver->forCountry('DE');
    expect($dual->jurisdiction())->toBe('CH+IT')
        ->and($dual->refusalJustification($report))->toContain('958f')->toContain('2220')
        ->and($dual->legalReferences())->toHaveCount(4);
});
