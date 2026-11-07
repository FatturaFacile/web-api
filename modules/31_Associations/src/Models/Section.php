<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A section of the association with separate accounts, kept on its own cost centre.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $cost_center_id
 */
final class Section extends AssociationRecord
{
    protected $table = 'assoc_sections';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
