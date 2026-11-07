<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * The contract of a sports collaborator. The kind is the one the consultant chose; the code does not decide it.
 *
 * @property string $collaborator_id
 */
final class SportContract extends AssociationRecord
{
    protected $table = 'assoc_sport_contracts';

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'agreed_amount' => 'decimal:2'];
    }
}
