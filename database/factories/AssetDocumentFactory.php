<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetDocument;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * @extends Factory<AssetDocument>
 */
final class AssetDocumentFactory extends Factory
{
    protected $model = AssetDocument::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'document_type' => 'warranty',
            'title' => 'Manufacturer Extended Warranty Certificate',
            'document_number' => 'WRT-'.strtoupper(Str::random(6)),
            'issue_date' => date('Y-m-d'),
            'expiry_date' => date('Y-m-d', strtotime('+3 years')),
        ];
    }
}
