<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\KpiDashboards\Domain\Enums\RoleCode;

/**
 * A cockpit layout: the system default of a role (user_id null) or the customized copy of one user.
 *
 * @property string $id
 * @property string|null $user_id
 * @property RoleCode $role_code
 * @property string|null $title_key
 * @property string|null $custom_title
 * @property array<string, mixed>|null $layout_grid_json
 * @property int $refresh_interval_seconds
 * @property bool $is_system_default
 * @property int $version
 */
final class KpiDashboard extends BaseEnterpriseModel
{
    protected $table = 'kpi_dashboards';

    protected function casts(): array
    {
        return [
            'role_code' => RoleCode::class,
            'layout_grid_json' => 'array',
            'is_system_default' => 'boolean',
            'refresh_interval_seconds' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<KpiWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(KpiWidget::class, 'dashboard_id')->orderBy('sort_order');
    }
}
