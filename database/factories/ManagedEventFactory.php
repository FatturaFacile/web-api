<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Events\Models\ManagedEvent;

/**
 * @extends Factory<ManagedEvent>
 */
final class ManagedEventFactory extends Factory
{
    protected $model = ManagedEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'EV-'.Str::upper(Str::random(6)),
            'name' => fake()->words(3, true),
            'starts_at' => '2027-09-10 09:00:00',
            'ends_at' => '2027-09-10 18:00:00',
            'status' => ManagedEvent::Draft,
            'capacity' => 100,
            'waitlist_enabled' => true,
            'currency' => 'EUR',
        ];
    }
}
