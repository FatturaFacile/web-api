<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\DataAsset;

/**
 * @extends Factory<DataAsset>
 */
final class DataAssetFactory extends Factory
{
    protected $model = DataAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Gestionale soci in cloud',
            'kind' => 'digital',
            'location' => 'Data center UE (Francoforte)',
            'description' => 'Anagrafica soci, tesseramenti e quote.',
            'confidentiality' => 3,
            'integrity' => 3,
            'availability' => 2,
            'owner' => 'Segreteria',
            'activity_family_ids' => [],
        ];
    }
}
