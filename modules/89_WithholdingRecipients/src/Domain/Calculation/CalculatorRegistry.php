<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Calculation;

use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;

/**
 * Picks the calculator (Strategy) of a regime.
 */
final class CalculatorRegistry
{
    /** @var array<string, CompensationCalculator> */
    private array $calculators = [];

    /**
     * @param  iterable<CompensationCalculator>  $calculators
     */
    public function __construct(iterable $calculators)
    {
        foreach ($calculators as $calculator) {
            $this->calculators[$calculator->regime()->value] = $calculator;
        }
    }

    public static function default(): self
    {
        return new self([
            new AgentCommissionCalculator,
            new AuthorRoyaltyCalculator,
            new OccasionalWorkerCalculator,
            new AmateurSportsCalculator,
            new SwissDeMinimisCalculator,
            new SwissForeignArtistCalculator,
        ]);
    }

    public function for(RecipientRegime $regime): CompensationCalculator
    {
        return $this->calculators[$regime->value];
    }

    public function calculate(CompensationInput $input): CompensationResult
    {
        return $this->for($input->regime)->calculate($input);
    }
}
