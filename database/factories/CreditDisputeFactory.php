<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditDispute;

/**
 * @extends Factory<CreditDispute>
 */
final class CreditDisputeFactory extends Factory
{
    protected $model = CreditDispute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'customer_id' => Contact::factory(),
            'disputed_amount' => '300.00',
            'reason_code' => 'quality',
            'status' => CreditDispute::Open,
            'opened_on' => now()->toDateString(),
        ];
    }
}
