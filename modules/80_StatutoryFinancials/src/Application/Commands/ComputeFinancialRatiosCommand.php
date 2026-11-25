<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

/**
 * Computes ROE, ROI, ROS, EBITDA, PFN/EBITDA, the liquidity and solvency ratios, the six-month DSCR and the crisis indicators
 * (Art. 2086 c.c. / Codice della Crisi d'Impresa, Art. 725 CO). Persists them on the deposit package unless it is a simulation.
 */
final readonly class ComputeFinancialRatiosCommand
{
    /**
     * @param  array<string, string>  $whatIf
     */
    public function __construct(public string $dossierId, public array $whatIf = [], public bool $persist = true, public ?string $userId = null) {}
}
