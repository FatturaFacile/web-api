<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Erasure;

use App\Domain\Privacy\Contracts\PrivacySelectiveErasureStrategyInterface;

final class ItalianGdprErasureStrategy implements PrivacySelectiveErasureStrategyInterface
{
    public function jurisdiction(): string
    {
        return 'IT';
    }

    public function legalReferences(): array
    {
        return ['Art. 17(3)(b) GDPR', 'Art. 2220 c.c.'];
    }

    public function refusalJustification(FiscalLockReport $report): string
    {
        return sprintf(
            'Erasure partially refused under Art. 17(3)(b) GDPR in conjunction with Art. 2220 c.c. (and DPR 600/1973): accounting records and invoices must be kept for ten years. The protected records can be erased after %s.',
            $report->retentionEndsAt?->toDateString() ?? 'the statutory period',
        );
    }
}
