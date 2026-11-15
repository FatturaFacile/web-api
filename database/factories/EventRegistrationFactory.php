<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Events\Models\EventRegistration;
use Modules\Events\Models\EventTicketType;
use Modules\Events\Models\ManagedEvent;

/**
 * @extends Factory<EventRegistration>
 */
final class EventRegistrationFactory extends Factory
{
    protected $model = EventRegistration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'event_id' => ManagedEvent::factory(),
            'ticket_type_id' => EventTicketType::factory(),
            'attendee_name' => fake()->name(),
            'status' => EventRegistration::Confirmed,
            'qr_token' => Str::random(40),
            'price' => '50.00',
            'registered_at' => '2027-06-15 09:00:00',
        ];
    }
}
