<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

/**
 * A service bay (lift, tyre station, paint booth) that holds one vehicle at a time.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
final class WorkshopBay extends WorkshopRecord
{
    protected $table = 'workshop_bays';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
