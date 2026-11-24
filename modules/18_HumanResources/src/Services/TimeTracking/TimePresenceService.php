<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\TimeClockEvent;

/**
 * Who is in now: the last event of each user in the last two local days decides, so a night shift counts and a forgotten
 * exit of last week does not.
 */
final class TimePresenceService
{
    /**
     * @return list<array{employee_id: string, employee_name: string, state: string, since: string, source: string}>
     */
    public function current(): array
    {
        $now = CarbonImmutable::now(SwissWorkingTimeRules::TIMEZONE);
        $events = TimeClockEvent::query()
            ->betweenLocalDays($now->subDay()->format('Y-m-d'), null)
            ->where('occurred_at', '<=', $now->utc()->format('Y-m-d H:i:s'))
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy('user_id');

        $present = $events->filter(static fn (TimeClockEvent $event): bool => $event->event_type !== TimeEventType::Out);
        $names = User::query()->whereIn('id', $present->keys())->pluck('name', 'id');

        return $present
            ->map(static fn (TimeClockEvent $event): array => [
                'employee_id' => (string) $event->user_id,
                'employee_name' => (string) $names->get($event->user_id),
                'state' => $event->event_type === TimeEventType::BreakStart ? 'on_break' : 'working',
                'since' => SwissWorkingTimeRules::toLocal($event->occurred_at)->toIso8601String(),
                'source' => $event->source->value,
            ])
            ->sortBy('employee_name')
            ->values()
            ->all();
    }
}
