<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\EventRegistration;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\TicketType;

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
            'tenant_id' => Tenant::factory(),
            'course_id' => static fn (array $attributes): string => Course::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'ticket_type_id' => static fn (array $attributes): string => TicketType::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'course_id' => $attributes['course_id'],
            ])->id,
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'status' => EventRegistration::Confirmed,
            'ticket_code' => Str::upper(Str::random(12)),
        ];
    }

    public function waitlisted(): static
    {
        return $this->state(['status' => EventRegistration::Waitlisted, 'waitlist_position' => 1]);
    }
}
