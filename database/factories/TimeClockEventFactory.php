<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Services\TimeTracking\TimeEventType;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;

/**
 * @extends Factory<TimeClockEvent>
 */
class TimeClockEventFactory extends Factory
{
    protected $model = TimeClockEvent::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'occurred_at' => '2026-11-02 08:00:00',
            'event_type' => TimeEventType::In,
            'source' => TimeRecordSource::Terminal,
        ];
    }
}
