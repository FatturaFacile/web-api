<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

final class Department extends AssociationRecord
{
    protected $table = 'assoc_departments';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
