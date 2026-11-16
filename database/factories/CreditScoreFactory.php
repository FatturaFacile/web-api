<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditScore;

/**
 * @extends Factory<CreditScore>
 */
final class CreditScoreFactory extends Factory
{
    protected $model = CreditScore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'customer_id' => Contact::factory(),
            'score' => '70.00',
            'rating' => 'B',
            'risk_level' => 'low',
            'breakdown' => [],
            'scored_on' => now()->toDateString(),
        ];
    }
}
