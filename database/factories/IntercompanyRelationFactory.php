<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\IntercompanyRelation;

/**
 * @extends Factory<IntercompanyRelation>
 */
final class IntercompanyRelationFactory extends Factory
{
    protected $model = IntercompanyRelation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => ConsolidationGroup::factory(),
            'tenant_id' => fn (array $attributes): string => (string) ConsolidationGroup::withoutGlobalScopes()->findOrFail($attributes['group_id'])->tenant_id,
            'entity_a_tenant_id' => Tenant::factory(),
            'entity_b_tenant_id' => Tenant::factory(),
            'tolerance_amount' => '0.0100',
        ];
    }
}
