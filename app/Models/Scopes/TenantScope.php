<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Enterprise\MultiTenant\Scopes\TenantScope as BaseTenantScope;

/**
 * Backward compatibility alias pointing to the authoritative Enterprise TenantScope.
 */
class TenantScope extends BaseTenantScope {}
