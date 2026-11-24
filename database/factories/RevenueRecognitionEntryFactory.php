<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\PerformanceObligation;
use Modules\SubscriptionBilling\Models\RevenueRecognitionEntry;

class RevenueRecognitionEntryFactory extends Factory
{
    protected $model = RevenueRecognitionEntry::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'performance_obligation_id' => PerformanceObligation::factory(),
            'recognition_date' => now()->toDateString(),
            'amount' => 120.00,
            'currency' => 'EUR',
            'audit_hash' => hash('sha256', Str::random(32)),
        ];
    }
}
