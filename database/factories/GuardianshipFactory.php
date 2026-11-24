<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<Guardianship>
 */
final class GuardianshipFactory extends Factory
{
    protected $model = Guardianship::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'minor_member_id' => static fn (array $attributes): string => Member::factory()->minor()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'guardian_contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'relationship' => 'parent',
            'is_primary' => true,
            'valid_from' => '2026-09-01',
            'valid_to' => null,
        ];
    }
}
