<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Warranty;

/**
 * Statutory warranty rule set for one jurisdiction. Implementations must be pure (no I/O, no DB access).
 */
interface WarrantyStatutoryRulesStrategyInterface
{
    public function supports(WarrantyContext $context): bool;

    public function calculate(WarrantyContext $context): WarrantyResult;
}
