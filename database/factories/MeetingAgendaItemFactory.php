<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingAgendaItem;

/**
 * @extends Factory<MeetingAgendaItem>
 */
final class MeetingAgendaItemFactory extends Factory
{
    protected $model = MeetingAgendaItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'meeting_id' => static fn (array $attributes): string => Meeting::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'position' => fake()->numberBetween(1, 10),
            'title' => fake()->randomElement(['Approvazione del bilancio', 'Rinnovo del consiglio direttivo', 'Quote associative', 'Varie ed eventuali']),
            'description' => null,
            'decision' => null,
        ];
    }
}
