<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Events\Models\EventSession;
use Modules\Events\Models\ManagedEvent;

/**
 * @extends Factory<EventSession>
 */
final class EventSessionFactory extends Factory
{
    protected $model = EventSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'event_id' => ManagedEvent::factory(),
            'title' => fake()->sentence(3),
            'starts_at' => '2027-09-10 10:00:00',
            'ends_at' => '2027-09-10 11:00:00',
        ];
    }
}
