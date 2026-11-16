<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Warranty;

use InvalidArgumentException;

/**
 * Deterministic, side-effect free warranty calculator: picks the statutory strategy by jurisdiction,
 * then applies extended care packs (effective expiry = max(base expiry, latest pack end)).
 */
final readonly class WarrantyCalculator
{
    /**
     * @var list<WarrantyStatutoryRulesStrategyInterface>
     */
    private array $strategies;

    /**
     * @param  list<WarrantyStatutoryRulesStrategyInterface>|null  $strategies
     */
    public function __construct(?array $strategies = null)
    {
        $this->strategies = $strategies ?? [
            new ChArt210CoTwoYearWarrantyStrategy,
            new ItCodiceConsumoB2cVsCc1495B2bStrategy,
        ];
    }

    /**
     * @throws InvalidArgumentException when no strategy supports the jurisdiction or an extension ends before delivery
     */
    public function calculate(WarrantyContext $context): WarrantyResult
    {
        $result = $this->strategyFor($context)->calculate($context);

        foreach ($context->extensions as $extension) {
            if ($extension->endsAt->lessThan($context->deliveryDate)) {
                throw new InvalidArgumentException('Extended care pack cannot end before the delivery date.');
            }

            if ($extension->endsAt->greaterThan($result->expiryDate)) {
                $result = $result->withExtension($extension);
            }
        }

        return $result;
    }

    private function strategyFor(WarrantyContext $context): WarrantyStatutoryRulesStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($context)) {
                return $strategy;
            }
        }

        throw new InvalidArgumentException(sprintf('No statutory warranty rules for jurisdiction "%s".', $context->jurisdiction));
    }
}
