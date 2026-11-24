<?php

declare(strict_types=1);

namespace Modules\Copilot\DTOs;

use App\Models\User;

/**
 * Who a tool acts for: the authenticated user inside the active tenant.
 */
final readonly class ToolContext
{
    public function __construct(
        public User $user,
        public string $tenantId,
        public string $locale,
    ) {}
}
