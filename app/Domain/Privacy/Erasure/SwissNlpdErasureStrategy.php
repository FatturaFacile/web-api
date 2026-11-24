<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Erasure;

use App\Domain\Privacy\Contracts\PrivacySelectiveErasureStrategyInterface;

final class SwissNlpdErasureStrategy implements PrivacySelectiveErasureStrategyInterface
{
    public function jurisdiction(): string
    {
        return 'CH';
    }

    public function legalReferences(): array
    {
        return ['Art. 32(2)(a) nLPD', 'Art. 958f CO / GeBüV (Olc)'];
    }

    public function refusalJustification(FiscalLockReport $report): string
    {
        return sprintf(
            'Erasure partially refused under Art. 32(2)(a) nLPD in conjunction with Art. 958f CO: accounting books, vouchers and business correspondence must be kept for ten years. The protected records can be erased after %s.',
            $report->retentionEndsAt?->toDateString() ?? 'the statutory period',
        );
    }
}
