<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryCountPolicy;

/**
 * The counting policy of the tenant (stored, or the defaults when none was saved yet).
 *
 * @mixin InventoryCountPolicy
 */
final class InventoryCountPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [];

        foreach (array_keys(InventoryCountPolicy::DEFAULTS) as $field) {
            $value = $this->resource->getAttribute($field);
            $data[$field] = $value;
        }

        return ['is_default' => ! $this->exists] + $data + [
            'cadence_a' => InventoryCountPolicy::cadenceLabel((int) $this->interval_days_a),
            'cadence_b' => InventoryCountPolicy::cadenceLabel((int) $this->interval_days_b),
            'cadence_c' => InventoryCountPolicy::cadenceLabel((int) $this->interval_days_c),
        ];
    }
}
