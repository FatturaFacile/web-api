<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;

/**
 * A reusable shift pattern (morning 06:00-14:00, night 22:00-06:00). The name is translatable (it, de, fr, en).
 *
 * @property string $code
 * @property array<string, string> $name
 * @property string $start_time H:i:s
 * @property string $end_time H:i:s
 * @property int $break_minutes
 * @property string $color_hex
 * @property bool $night_surcharge_applicable
 * @property bool $is_on_call
 * @property bool $is_active
 */
final class ShiftTemplate extends BaseEnterpriseModel
{
    protected $table = 'roster_shift_templates';

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'break_minutes' => 'integer',
            'night_surcharge_applicable' => 'boolean',
            'is_on_call' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function localizedName(string $locale): string
    {
        return $this->name[$locale] ?? $this->name['en'] ?? (string) reset($this->name);
    }

    /**
     * The absolute start and end of the template on a local date. An end time at or before the start time means the shift
     * ends the next day (a night shift).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function instantsOn(CarbonImmutable $localDate, Jurisdiction $jurisdiction): array
    {
        $timezone = $jurisdiction->timezone();
        $day = CarbonImmutable::parse($localDate->format('Y-m-d'), $timezone);
        [$startHour, $startMinute] = array_map('intval', explode(':', $this->start_time));
        [$endHour, $endMinute] = array_map('intval', explode(':', $this->end_time));

        $start = $day->setTime($startHour, $startMinute);
        $end = $day->setTime($endHour, $endMinute);

        if ($end <= $start) {
            $end = $day->addDay()->setTime($endHour, $endMinute);
        }

        return [$start->utc(), $end->utc()];
    }
}
