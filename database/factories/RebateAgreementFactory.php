<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Rebates\Models\RebateAgreement;

/**
 * @extends Factory<RebateAgreement>
 */
final class RebateAgreementFactory extends Factory
{
    protected $model = RebateAgreement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'RB-'.Str::upper(Str::random(6)),
            'name' => fake()->words(3, true),
            'direction' => RebateAgreement::Sales,
            'contact_id' => Contact::factory(),
            'tier_mode' => RebateAgreement::Retroactive,
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'currency' => 'EUR',
            'status' => RebateAgreement::Draft,
        ];
    }
}
