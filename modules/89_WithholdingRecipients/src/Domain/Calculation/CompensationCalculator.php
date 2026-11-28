<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;

/**
 * Strategy: one tax calculator per regime.
 */
interface CompensationCalculator
{
    public function regime(): RecipientRegime;

    public function calculate(CompensationInput $input): CompensationResult;
}
