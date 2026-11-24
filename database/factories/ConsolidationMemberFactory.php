<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Enums\ConsolidationMethod;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;

/**
 * @extends Factory<ConsolidationMember>
 */
final class ConsolidationMemberFactory extends Factory
{
    protected $model = ConsolidationMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => ConsolidationGroup::factory(),
            'tenant_id' => fn (array $attributes): string => (string) ConsolidationGroup::withoutGlobalScopes()->findOrFail($attributes['group_id'])->tenant_id,
            'member_tenant_id' => Tenant::factory(),
            'ownership_percentage' => '100',
            'consolidation_method' => ConsolidationMethod::Full->value,
            'is_parent' => false,
        ];
    }
}
