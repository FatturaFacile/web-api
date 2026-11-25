<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

/**
 * What-if simulation of the ratios, the six-month DSCR and the crisis indicators: nothing is stored.
 */
final readonly class SimulateCrisisIndicatorsQuery
{
    /**
     * @param  array<string, string>  $whatIf
     */
    public function __construct(public string $dossierId, public array $whatIf = []) {}
}
