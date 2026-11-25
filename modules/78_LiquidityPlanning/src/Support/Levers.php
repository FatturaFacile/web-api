<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Support;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The levers of a what-if scenario. Every value is clamped to its range so that a scenario can never break the projection;
 * the neutral levers leave the baseline untouched.
 */
final readonly class Levers
{
    public function __construct(
        public int $collectionDelayDays = 0,
        public int $collectionDelaySharePercent = 100,
        public string $inflowChangePercent = '0',
        public string $outflowChangePercent = '0',
        public string $rawMaterialIncreasePercent = '0',
        public int $capexAccelerationDays = 0,
        public string $capexChangePercent = '0',
        public string $additionalFinancingAmount = '0',
        public ?string $additionalFinancingDate = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $date = isset($data['additional_financing_date']) && $data['additional_financing_date'] !== ''
            ? CarbonImmutable::parse((string) $data['additional_financing_date'])->toDateString()
            : null;

        return new self(
            collectionDelayDays: max(0, min(180, (int) ($data['collection_delay_days'] ?? 0))),
            collectionDelaySharePercent: max(0, min(100, (int) ($data['collection_delay_share_percent'] ?? 100))),
            inflowChangePercent: self::clamp($data['inflow_change_percent'] ?? 0, '-100', '100'),
            outflowChangePercent: self::clamp($data['outflow_change_percent'] ?? 0, '-100', '300'),
            rawMaterialIncreasePercent: self::clamp($data['raw_material_increase_percent'] ?? 0, '0', '300'),
            capexAccelerationDays: max(0, min(365, (int) ($data['capex_acceleration_days'] ?? 0))),
            capexChangePercent: self::clamp($data['capex_change_percent'] ?? 0, '-100', '300'),
            additionalFinancingAmount: Decimal::round2(Decimal::compare(self::clamp($data['additional_financing_amount'] ?? 0, '0', '999999999999'), '0') > 0 ? self::clamp($data['additional_financing_amount'], '0', '999999999999') : '0'),
            additionalFinancingDate: $date,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'collection_delay_days' => $this->collectionDelayDays,
            'collection_delay_share_percent' => $this->collectionDelaySharePercent,
            'inflow_change_percent' => $this->inflowChangePercent,
            'outflow_change_percent' => $this->outflowChangePercent,
            'raw_material_increase_percent' => $this->rawMaterialIncreasePercent,
            'capex_acceleration_days' => $this->capexAccelerationDays,
            'capex_change_percent' => $this->capexChangePercent,
            'additional_financing_amount' => $this->additionalFinancingAmount,
            'additional_financing_date' => $this->additionalFinancingDate,
        ];
    }

    public function isNeutral(): bool
    {
        return $this->collectionDelayDays === 0
            && Decimal::isZero($this->inflowChangePercent)
            && Decimal::isZero($this->outflowChangePercent)
            && Decimal::isZero($this->rawMaterialIncreasePercent)
            && $this->capexAccelerationDays === 0
            && Decimal::isZero($this->capexChangePercent)
            && Decimal::isZero($this->additionalFinancingAmount);
    }

    private static function clamp(mixed $value, string $min, string $max): string
    {
        $number = is_scalar($value) && preg_match('/^[+-]?\d+(\.\d+)?$/', (string) $value) === 1 ? (string) $value : '0';

        if (Decimal::compare($number, $min) < 0) {
            return Decimal::round2($min);
        }

        return Decimal::round2(Decimal::compare($number, $max) > 0 ? $max : $number);
    }
}
