<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DocumentTrust\Models\PreservationPackage;

/**
 * @extends Factory<PreservationPackage>
 */
final class PreservationPackageFactory extends Factory
{
    protected $model = PreservationPackage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'document_class' => 'issued_invoice',
            'name' => 'Fatture emesse '.fake()->year(),
            'status' => PreservationPackage::Open,
            'items_count' => 0,
            'total_bytes' => 0,
        ];
    }
}
