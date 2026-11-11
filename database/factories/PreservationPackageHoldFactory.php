<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Models\PreservationPackageHold;

/**
 * @extends Factory<PreservationPackageHold>
 */
final class PreservationPackageHoldFactory extends Factory
{
    protected $model = PreservationPackageHold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => PreservationPackage::factory(),
            'tenant_id' => static fn (array $attributes): string => (string) PreservationPackage::withoutGlobalScopes()->findOrFail($attributes['package_id'])->tenant_id,
            'reason' => 'Dispute pending',
            'placed_at' => now(),
        ];
    }
}
