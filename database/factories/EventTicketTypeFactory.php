<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Events\Models\EventTicketType;
use Modules\Events\Models\ManagedEvent;

/**
 * @extends Factory<EventTicketType>
 */
final class EventTicketTypeFactory extends Factory
{
    protected $model = EventTicketType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'event_id' => ManagedEvent::factory(),
            'name' => 'Standard',
            'price' => '50.00',
            'quantity' => null,
            'is_active' => true,
        ];
    }
}
