<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * Who is inside an area: a row stays open until the person leaves.
 */
final class AreaPresence extends AssociationEntry
{
    protected $table = 'assoc_area_presences';

    protected function casts(): array
    {
        return ['entered_at' => 'datetime', 'left_at' => 'datetime'];
    }
}
