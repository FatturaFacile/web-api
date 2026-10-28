<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\LookupItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        $scheduledStatus = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'appointment_status'))
            ->where('code', 'scheduled')
            ->first();

        $startsAt = fake()->dateTimeBetween('now', '+2 weeks');
        $endsAt = (clone $startsAt)->modify('+2 hours');

        return [
            'tenant_id' => $tenantId,
            'appointment_number' => 'APT-'.fake()->unique()->numerify('2026-####'),
            'status_id' => $scheduledStatus?->id ?? LookupItem::first()?->id,
            'contact_id' => Contact::first()?->id ?? Contact::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => false,
            'address' => fake()->streetAddress().', '.fake()->city(),
            'latitude' => fake()->latitude(45.0, 46.5),
            'longitude' => fake()->longitude(8.5, 10.5),
            'estimated_travel_minutes' => 30,
            'estimated_duration_minutes' => 120,
            'technician_notes' => 'Portare chiave speciale per quadro elettrico.',
            'customer_notes' => 'Citofonare scala B interno 4.',
            'is_portal_visible' => true,
            'created_by' => User::first()?->id ?? User::factory(),
            'updated_by' => User::first()?->id ?? User::factory(),
        ];
    }
}
