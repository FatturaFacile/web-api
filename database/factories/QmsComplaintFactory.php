<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsComplaint;

/**
 * @extends Factory<QmsComplaint>
 */
final class QmsComplaintFactory extends Factory
{
    protected $model = QmsComplaint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'number' => 'CMP-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'customer_name' => fake()->company(),
            'received_at' => now()->toDateString(),
            'channel' => 'email',
            'description' => fake()->sentence(),
            'severity' => 'minor',
            'status' => 'received',
        ];
    }
}
