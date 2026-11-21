<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Application\Commands;

use App\Models\User;
use Modules\KpiDashboards\Domain\Enums\RoleCode;

/**
 * Clones a system cockpit into the user's own layout, or updates the user's custom layout in place.
 */
final readonly class SaveCustomDashboardLayoutCommand
{
    /**
     * @param  list<array{kpi_code: string, widget_type?: string|null, default_time_comparison?: string|null, x: int, y: int, w: int, h: int, threshold_rules?: array<string, mixed>|null}>  $widgets
     */
    public function __construct(
        public string $tenantId,
        public User $user,
        public ?string $dashboardId,
        public RoleCode $role,
        public ?string $customTitle,
        public int $refreshIntervalSeconds,
        public array $widgets,
        public ?int $expectedVersion = null,
    ) {}
}
