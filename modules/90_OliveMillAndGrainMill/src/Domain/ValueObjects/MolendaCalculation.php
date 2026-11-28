<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\ValueObjects;

/**
 * Outcome of the molenda (in-kind payment) maths. Amounts are decimal strings with two decimals, weights have three.
 * cash_balance is the service total minus the value of the retained oil: positive when the farmer still owes money to the mill, negative when the
 * mill owes the farmer, zero when the two documents offset each other.
 */
final readonly class MolendaCalculation
{
    public function __construct(
        public string $serviceNet,
        public string $serviceVatRate,
        public string $serviceVat,
        public string $serviceTotal,
        public string $retainedKg,
        public string $retainedPercent,
        public string $oilPricePerKg,
        public string $retainedValueNet,
        public string $purchaseVatRate,
        public string $purchaseVat,
        public string $purchaseTotal,
        public string $cashBalance,
        public string $compensation,
        public string $cashDirection,
        public string $farmerPickupKg,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $result = [];

        foreach (get_object_vars($this) as $name => $value) {
            $result[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name))] = $value;
        }

        return $result;
    }
}
