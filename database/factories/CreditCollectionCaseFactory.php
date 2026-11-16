<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditCollectionCase;

/**
 * @extends Factory<CreditCollectionCase>
 */
final class CreditCollectionCaseFactory extends Factory
{
    protected $model = CreditCollectionCase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'customer_id' => Contact::factory(),
            'case_number' => 'CC-'.Str::upper(Str::random(8)),
            'status' => CreditCollectionCase::Open,
            'priority' => 'normal',
            'outstanding_amount' => '1000.00',
            'currency' => 'EUR',
            'opened_on' => now()->toDateString(),
        ];
    }
}
