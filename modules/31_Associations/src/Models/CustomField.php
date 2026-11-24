<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

final class CustomField extends AssociationRecord
{
    protected $table = 'assoc_custom_fields';

    protected function casts(): array
    {
        return ['options' => 'array', 'is_required' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
