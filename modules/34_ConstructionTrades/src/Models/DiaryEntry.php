<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

/**
 * The site diary of a day: weather, workers present, the work performed and any incident. One entry per site and day.
 *
 * @property string $id
 * @property string $construction_site_id
 * @property int $workers_present
 */
final class DiaryEntry extends TradesRecord
{
    protected $table = 'ct_site_diary_entries';

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'workers_present' => 'integer'];
    }
}
