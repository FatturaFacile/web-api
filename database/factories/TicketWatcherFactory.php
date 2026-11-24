<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketWatcher;

/**
 * @extends Factory<TicketWatcher>
 */
class TicketWatcherFactory extends Factory
{
    protected $model = TicketWatcher::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'ticket_id' => Ticket::factory(),
            'tenant_id' => fn (array $attributes): ?string => Ticket::query()->withoutGlobalScopes()->find($attributes['ticket_id'])?->tenant_id,
            'user_id' => User::factory(),
        ];
    }
}
