<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\MembershipType;

/**
 * @extends Factory<Membership>
 */
final class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'year_id' => static fn (array $attributes): string => AssociativeYear::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'name' => 'Anno sociale 2026',
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-12-31',
            ])->id,
            'membership_type_id' => static fn (array $attributes): string => MembershipType::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'status' => MembershipStatus::Active,
            'card_sequence' => fake()->unique()->numberBetween(1, 999_999),
            'card_number' => static fn (array $attributes): string => sprintf('%06d', $attributes['card_sequence']),
            'issued_on' => '2026-01-15',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
        ];
    }
}
