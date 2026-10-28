<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Enterprise\MultiTenant\Concerns\BelongsToTenant as EnterpriseBelongsToTenant;

/**
 * Backward compatibility trait proxying to the authoritative Enterprise BelongsToTenant.
 */
trait BelongsToTenant
{
    use EnterpriseBelongsToTenant;
}
