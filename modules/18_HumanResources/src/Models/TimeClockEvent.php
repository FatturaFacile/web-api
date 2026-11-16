<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Builder;
use Modules\HumanResources\Casts\UtcInstant;
use Modules\HumanResources\Models\Concerns\ProtectsTimeRecord;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\TimeTracking\TimeEventType;
use Modules\HumanResources\Services\TimeTracking\TimeRecordSource;

/**
 * A time clock event (timbratura): entry, exit, start or end of a break. Corrections are audited and deletions are
 * logical, so that the records can be shown to the labour inspectorate (art. 73 OLL 1).
 *
 * @property CarbonImmutable $occurred_at
 * @property TimeEventType $event_type
 * @property TimeRecordSource $source
 * @property string|null $source_device
 * @property string|null $amended_from_id
 * @property string|null $gps_lat
 * @property string|null $gps_lng
 * @property CarbonImmutable|null $geolocation_consent_at
 */
final class TimeClockEvent extends BaseEnterpriseModel
{
    use ProtectsTimeRecord;

    protected $table = 'time_clock_events';

    protected function casts(): array
    {
        return [
            'occurred_at' => UtcInstant::class,
            'event_type' => TimeEventType::class,
            'source' => TimeRecordSource::class,
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'geolocation_consent_at' => UtcInstant::class,
            'amended_from_id' => 'string',
            'source_device' => 'string',
        ];
    }

    /**
     * Restrict the query to local days, both optional and inclusive.
     *
     * @param  Builder<TimeClockEvent>  $query
     * @return Builder<TimeClockEvent>
     */
    public function scopeBetweenLocalDays(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $inner): Builder => $inner->where('occurred_at', '>=', SwissWorkingTimeRules::localDate((string) $from)->utc()->format('Y-m-d H:i:s')))
            ->when($to, fn (Builder $inner): Builder => $inner->where('occurred_at', '<', SwissWorkingTimeRules::localDate((string) $to)->addDay()->utc()->format('Y-m-d H:i:s')));
    }

    /**
     * The values kept in the history of the changes.
     *
     * @return array{occurred_at: string, event_type: string, source: string, note: string|null}
     */
    public function auditValues(): array
    {
        return [
            'occurred_at' => SwissWorkingTimeRules::toLocal($this->occurred_at)->toIso8601String(),
            'event_type' => $this->event_type->value,
            'source' => $this->source->value,
            'note' => $this->note,
            'source_device' => $this->source_device,
            'amended_from_id' => $this->amended_from_id,
            'has_gps' => $this->gps_lat !== null,
        ];
    }
}
