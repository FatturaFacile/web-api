<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<FeeAssignment>
 */
final class FeeAssignmentFactory extends Factory
{
    protected $model = FeeAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'fee_plan_id' => static fn (array $attributes): string => FeePlan::factory()->create(['tenant_id' => $attributes['tenant_id'], 'amount' => '300.00'])->id,
            'payer_contact_id' => static fn (array $attributes): string => Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'description' => 'Quota annuale stagione 2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'gross_amount' => '300.00',
            'discount_amount' => '0.00',
            'net_amount' => '300.00',
            'status' => FeeAssignment::Active,
        ];
    }
}
