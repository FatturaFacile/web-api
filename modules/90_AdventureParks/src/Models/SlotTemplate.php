<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\AdventureParks\Domain\ValueObjects\SlotWindow;

/**
 * A recurring time slot of the park (start, end, wristband colour, weekdays it runs).
 *
 * @property string $start_time
 * @property string $end_time
 * @property string $wristband_color
 * @property list<int>|null $weekdays
 * @property bool $is_active
 */
final class SlotTemplate extends BaseEnterpriseModel
{
    protected $table = 'adv_slot_templates';

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function window(): SlotWindow
    {
        return SlotWindow::fromTimes($this->start_time, $this->end_time);
    }

    public function isOpenOn(CarbonInterface $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $weekdays = $this->weekdays;

        return $weekdays === null || $weekdays === [] || in_array($date->dayOfWeekIso, array_map('intval', $weekdays), true);
    }
}
