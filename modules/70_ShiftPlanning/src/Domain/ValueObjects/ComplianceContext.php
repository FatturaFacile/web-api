<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\ValueObjects;

/**
 * Facts about the employee that the strategies need and the plan does not hold.
 */
final readonly class ComplianceContext
{
    /**
     * @param  bool  $regularNightWork  true for permanent or regularly recurring night work (Art. 17b para. 2 ArG: 10% time
     *                                  compensation), false for temporary night work (25% wage supplement)
     */
    public function __construct(public bool $regularNightWork = false) {}
}
