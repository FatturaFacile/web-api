<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A discipline with its code. Rows without a tenant are generic.
 */
final class Discipline extends AssociationRecord
{
    use AllowsGlobalRecords, BelongsToTenant;

    protected $table = 'assoc_disciplines';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
