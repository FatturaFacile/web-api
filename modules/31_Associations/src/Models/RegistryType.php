<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Modules\Associations\Enums\RegistryKind;

/**
 * A type of the registry (member, volunteer, a custom category...). Rows without a tenant are generic.
 */
final class RegistryType extends AssociationRecord
{
    use AllowsGlobalRecords, BelongsToTenant;

    protected $table = 'assoc_registry_types';

    protected function casts(): array
    {
        return ['kind' => RegistryKind::class, 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
