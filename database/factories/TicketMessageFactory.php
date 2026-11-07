<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketMessage;

/**
 * @extends Factory<TicketMessage>
 */
class TicketMessageFactory extends Factory
{
    protected $model = TicketMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'ticket_id' => Ticket::factory(),
            'tenant_id' => fn (array $attributes): ?string => Ticket::query()->withoutGlobalScopes()->find($attributes['ticket_id'])?->tenant_id,
            'author_type' => 'customer',
            'author_name' => fake()->name(),
            'author_email' => fake()->safeEmail(),
            'visibility' => 'public',
            'channel' => 'portal',
            'body' => fake()->paragraph(),
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (): array => ['author_type' => 'staff', 'visibility' => 'internal', 'channel' => 'web']);
    }
}
