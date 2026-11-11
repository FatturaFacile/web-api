<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Models\PreservationVerification;

/**
 * @extends Factory<PreservationVerification>
 */
final class PreservationVerificationFactory extends Factory
{
    protected $model = PreservationVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => PreservationPackage::factory(),
            'tenant_id' => static fn (array $attributes): string => (string) PreservationPackage::withoutGlobalScopes()->findOrFail($attributes['package_id'])->tenant_id,
            'result' => 'passed',
            'trigger' => 'manual',
            'manifest_ok' => true,
            'checked_items' => 1,
            'failed_items' => 0,
            'failures' => null,
            'verified_at' => now(),
        ];
    }
}
