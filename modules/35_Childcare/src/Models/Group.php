<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

/**
 * A group or room of the kindergarten.
 */
final class Group extends ChildcareRecord
{
    protected $table = 'cc_groups';

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }
}
