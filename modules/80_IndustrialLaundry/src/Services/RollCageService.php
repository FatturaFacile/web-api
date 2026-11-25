<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Models\RollCage;
use Modules\IndustrialLaundry\Support\Money;

/**
 * The register of roll cages (returnable containers) with their tare weight.
 */
final class RollCageService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(string $tenantId, array $data, ?string $userId, ?RollCage $cage = null): RollCage
    {
        $attributes = [];

        foreach (['barcode', 'label', 'tare_weight_kg', 'max_gross_weight_kg', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (isset($attributes['tare_weight_kg'])) {
            $attributes['tare_weight_kg'] = Money::weight($attributes['tare_weight_kg']);
        }

        if (isset($attributes['max_gross_weight_kg']) && isset($attributes['tare_weight_kg']) && Money::compare((string) $attributes['max_gross_weight_kg'], (string) $attributes['tare_weight_kg']) < 0) {
            throw new IndustrialLaundryException('gross_below_tare', 422, ['gross' => Money::weight((string) $attributes['max_gross_weight_kg']), 'tare' => (string) $attributes['tare_weight_kg']]);
        }

        if ($cage === null) {
            return RollCage::query()->create($attributes + ['tenant_id' => $tenantId, 'created_by' => $userId]);
        }

        $cage->forceFill($attributes + ['updated_by' => $userId])->save();

        return $cage;
    }
}
