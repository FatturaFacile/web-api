<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\TimeAuditEntry;

/**
 * @extends Factory<TimeAuditEntry>
 */
final class TimeAuditEntryFactory extends Factory
{
    protected $model = TimeAuditEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'entity_type' => 'TimeClockEvent',
            'entity_id' => (string) Str::uuid7(),
            'action' => 'Modified',
            'before_values' => ['occurred_at' => '2026-11-02 08:12:00', 'event_type' => 'in'],
            'after_values' => ['occurred_at' => '2026-11-02 08:00:00', 'event_type' => 'in'],
            'reason' => 'Timbratura dimenticata, corretta su indicazione del responsabile',
            'actor_id' => null,
            'actor_name' => fake()->name(),
            'occurred_at' => '2026-11-02 10:30:00',
        ];
    }
}
