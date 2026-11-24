<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetAssignment;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * @extends Factory<AssetAssignment>
 */
final class AssetAssignmentFactory extends Factory
{
    protected $model = AssetAssignment::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'company_id' => function (array $attrs) {
                return $attrs['tenant_id'];
            },
            'asset_id' => function (array $attrs) {
                return FixedAsset::factory()->capitalized()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'assignment_number' => 'ASN-'.strtoupper(Str::random(8)),
            'assignee_type' => 'employee',
            'assignee_name' => 'Ing. Marco Rossi',
            'assigned_at' => date('Y-m-d'),
            'condition_on_assignment' => 'good',
            'status' => 'active',
        ];
    }
}
