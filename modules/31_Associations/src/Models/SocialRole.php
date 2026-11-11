<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * An office of the body, such as president or secretary. Rows without a tenant are generic.
 */
final class SocialRole extends AssociationRecord
{
    use AllowsGlobalRecords, BelongsToTenant;

    protected $table = 'assoc_social_roles';

    protected function casts(): array
    {
        return ['is_single_holder' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
