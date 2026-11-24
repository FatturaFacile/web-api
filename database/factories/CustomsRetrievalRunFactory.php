<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\Customs\Models\CustomsRetrievalRun;

/**
 * @extends Factory<CustomsRetrievalRun>
 */
final class CustomsRetrievalRunFactory extends Factory
{
    protected $model = CustomsRetrievalRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $day = Carbon::instance(fake()->dateTimeBetween('2026-01-01', '2026-12-31'))->startOfDay();
        $listed = fake()->numberBetween(0, 25);

        return [
            'tenant_id' => Tenant::factory(),
            'gateway' => 'edec',
            'date_from' => $day->toDateString(),
            'date_to' => $day->toDateString(),
            'status' => 'completed',
            'listed_count' => $listed,
            'stored_count' => $listed,
            'duplicate_count' => 0,
            'failed_count' => 0,
            'errors' => [],
            'started_at' => $day->copy()->addDay()->setTime(6, 0),
            'finished_at' => $day->copy()->addDay()->setTime(6, 2),
        ];
    }
}
