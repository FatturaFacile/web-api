<?php

declare(strict_types=1);

namespace Modules\Plm\Strategies;

use InvalidArgumentException;
use Modules\Plm\Contracts\IPlmRegulatoryComplianceStrategy;

final class PlmRegulatoryComplianceStrategyFactory
{
    /**
     * @param  array<string, IPlmRegulatoryComplianceStrategy>  $strategies
     */
    public function __construct(private readonly array $strategies = []) {}

    public function resolve(?string $strategyCode = null): IPlmRegulatoryComplianceStrategy
    {
        $code = $strategyCode !== null && $strategyCode !== '' ? $strategyCode : CeUkcaMachineryRoHsStrategy::CODE;

        if (isset($this->strategies[$code])) {
            return $this->strategies[$code];
        }

        return match ($code) {
            CeUkcaMachineryRoHsStrategy::CODE => new CeUkcaMachineryRoHsStrategy,
            default => throw new InvalidArgumentException("Unsupported PLM compliance strategy [{$code}]."),
        };
    }
}
