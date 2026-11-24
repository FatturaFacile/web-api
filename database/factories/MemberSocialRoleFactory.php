<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MemberSocialRole;
use Modules\Associations\Models\SocialRole;

/**
 * @extends Factory<MemberSocialRole>
 */
final class MemberSocialRoleFactory extends Factory
{
    protected $model = MemberSocialRole::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'social_role_id' => static fn (array $attributes): string => SocialRole::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'valid_from' => '2026-04-30',
            'valid_to' => '2029-04-30',
            'notes' => null,
        ];
    }
}
