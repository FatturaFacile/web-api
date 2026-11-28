<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Commands;

use Modules\OliveMillAndGrainMill\Domain\Enums\SettlementMode;
use Modules\OliveMillAndGrainMill\Domain\Enums\TariffMode;

/**
 * Settles a completed milling cycle (cash, in kind or hybrid); with simulate only the calculation is returned.
 */
final readonly class SettleMillingCycleCommand
{
    public function __construct(
        public string $cycleId,
        public TariffMode $tariffMode,
        public float $tariffValue,
        public ?float $serviceVatRate,
        public SettlementMode $settlementMode,
        public ?float $molendaPercent,
        public ?float $fixedRetainedKg,
        public ?float $oilPricePerKg,
        public ?float $purchaseVatRate,
        public ?string $tankId,
        public ?string $notes,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            cycleId: (string) $data['cycle_id'],
            tariffMode: TariffMode::from((string) $data['tariff_mode']),
            tariffValue: (float) $data['tariff_value'],
            serviceVatRate: isset($data['service_vat_rate']) ? (float) $data['service_vat_rate'] : null,
            settlementMode: SettlementMode::from((string) $data['settlement_mode']),
            molendaPercent: isset($data['molenda_percent']) ? (float) $data['molenda_percent'] : null,
            fixedRetainedKg: isset($data['fixed_retained_kg']) ? (float) $data['fixed_retained_kg'] : null,
            oilPricePerKg: isset($data['oil_price_per_kg']) ? (float) $data['oil_price_per_kg'] : null,
            purchaseVatRate: isset($data['purchase_vat_rate']) ? (float) $data['purchase_vat_rate'] : null,
            tankId: isset($data['tank_id']) ? (string) $data['tank_id'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            userId: $userId,
        );
    }
}
