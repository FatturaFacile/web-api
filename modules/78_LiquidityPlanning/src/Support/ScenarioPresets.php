<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Support;

/**
 * The built-in what-if presets (not stored): a share of the collections slipping later, a raw-material price spike and the
 * acceleration of the investments.
 */
final class ScenarioPresets
{
    /**
     * @return list<array{code: string, name_key: string, description_key: string, levers: array<string, int|string|null>}>
     */
    public static function all(): array
    {
        return array_map(static fn (string $code, Levers $levers): array => [
            'code' => $code,
            'name_key' => 'lpApi.preset.'.$code.'.name',
            'description_key' => 'lpApi.preset.'.$code.'.description',
            'levers' => $levers->toArray(),
        ], array_keys(self::levers()), array_values(self::levers()));
    }

    /**
     * @return array<string, Levers>
     */
    public static function levers(): array
    {
        return [
            'collection_delay_20' => new Levers(collectionDelayDays: 30, collectionDelaySharePercent: 20),
            'raw_material_spike' => new Levers(rawMaterialIncreasePercent: '15.00'),
            'capex_acceleration' => new Levers(capexAccelerationDays: 90),
        ];
    }

    public static function stress(): Levers
    {
        return self::levers()['collection_delay_20'];
    }
}
