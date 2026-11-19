<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Erasure;

use App\Domain\Privacy\Contracts\PrivacySelectiveErasureStrategyInterface;

/**
 * Cross-border tenants (or any country other than CH and IT): both regimes apply, so both are cited.
 */
final readonly class DualCrossBorderErasureStrategy implements PrivacySelectiveErasureStrategyInterface
{
    public function __construct(
        private SwissNlpdErasureStrategy $swiss = new SwissNlpdErasureStrategy,
        private ItalianGdprErasureStrategy $italian = new ItalianGdprErasureStrategy,
    ) {}

    public function jurisdiction(): string
    {
        return 'CH+IT';
    }

    public function legalReferences(): array
    {
        return [...$this->italian->legalReferences(), ...$this->swiss->legalReferences()];
    }

    public function refusalJustification(FiscalLockReport $report): string
    {
        return $this->italian->refusalJustification($report).' '.$this->swiss->refusalJustification($report);
    }
}
