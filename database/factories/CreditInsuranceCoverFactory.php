<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditInsuranceCover;
use Modules\CreditManagement\Models\CreditInsurancePolicy;

/**
 * @extends Factory<CreditInsuranceCover>
 */
final class CreditInsuranceCoverFactory extends Factory
{
    protected $model = CreditInsuranceCover::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'policy_id' => CreditInsurancePolicy::factory(),
            'customer_id' => Contact::factory(),
            'insured_limit' => '20000.00',
            'status' => CreditInsuranceCover::Granted,
        ];
    }
}
