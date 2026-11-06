<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Italian;

/**
 * A level of a collective agreement with its monthly minimum.
 */
final readonly class CollectiveAgreementLevelData
{
    public function __construct(public string $level, public string $monthlyMinimum) {}
}
