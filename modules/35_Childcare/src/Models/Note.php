<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

/**
 * An observation about a child on a day. The ones visible to the family make up the daily report.
 *
 * @property string $child_id
 * @property string $body
 * @property bool $visible_to_family
 */
final class Note extends ChildcareRecord
{
    /** @var list<string> */
    public const array Categories = ['general', 'meal', 'sleep', 'activity', 'health', 'behaviour'];

    protected $table = 'cc_notes';

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'body' => 'encrypted', 'visible_to_family' => 'boolean'];
    }
}
