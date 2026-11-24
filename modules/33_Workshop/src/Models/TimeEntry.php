<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use Carbon\CarbonImmutable;

/**
 * The time a technician worked on a folder: a running entry has no end yet; a stopped one holds whole minutes. Billing
 * the time links the entries to the labour line made from them.
 *
 * @property string $id
 * @property string $folder_id
 * @property string $technician_user_id
 * @property ?string $folder_line_id
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $ended_at
 * @property ?int $minutes
 */
final class TimeEntry extends WorkshopRecord
{
    protected $table = 'workshop_time_entries';

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'minutes' => 'integer'];
    }
}
