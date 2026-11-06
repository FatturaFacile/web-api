<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * Remembers that a reminder went out, so that a repeated run of a job never sends it twice.
 */
final class Notice extends AssociationEntry
{
    protected $table = 'assoc_notices';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'days_before' => 'integer'];
    }
}
