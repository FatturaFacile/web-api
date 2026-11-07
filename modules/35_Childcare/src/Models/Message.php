<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

/**
 * A message between the kindergarten and the family of a child.
 *
 * @property string $child_id
 * @property string $direction
 * @property string $body
 */
final class Message extends ChildcareRecord
{
    protected $table = 'cc_messages';

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'read_at' => 'immutable_datetime'];
    }
}
