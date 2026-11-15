<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditLimitRequest;

/**
 * @extends Factory<CreditLimitRequest>
 */
final class CreditLimitRequestFactory extends Factory
{
    protected $model = CreditLimitRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'customer_id' => Contact::factory(),
            'current_limit' => '0.00',
            'requested_limit' => '10000.00',
            'currency' => 'EUR',
            'status' => CreditLimitRequest::Pending,
        ];
    }
}
