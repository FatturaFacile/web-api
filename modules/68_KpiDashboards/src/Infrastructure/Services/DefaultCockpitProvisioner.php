<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\KpiDashboards\Domain\Enums\ComparisonMode;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\Enums\RoleCode;
use Modules\KpiDashboards\Infrastructure\Models\KpiDashboard;
use Modules\KpiDashboards\Infrastructure\Models\KpiWidget;

/**
 * Creates the five preconfigured system cockpits of a tenant on first use, and clones one into a custom cockpit.
 */
final class DefaultCockpitProvisioner
{
    public const int Columns = 12;

    /**
     * The system default of the role, created with its widgets when the tenant has none yet.
     */
    public function systemDefault(string $tenantId, RoleCode $role): KpiDashboard
    {
        $existing = KpiDashboard::query()->where('tenant_id', $tenantId)->where('role_code', $role->value)->where('is_system_default', true)->first();

        if ($existing instanceof KpiDashboard) {
            return $existing;
        }

        return DB::transaction(function () use ($tenantId, $role): KpiDashboard {
            $dashboard = KpiDashboard::create([
                'tenant_id' => $tenantId,
                'user_id' => null,
                'role_code' => $role,
                'title_key' => 'KPI_DASHBOARDS.DASHBOARD_'.strtoupper($role->value),
                'layout_grid_json' => ['columns' => self::Columns, 'row_height' => 80, 'compact' => 'vertical'],
                'refresh_interval_seconds' => 300,
                'is_system_default' => true,
                'version' => 1,
            ]);

            foreach ($this->kpisOf($role) as $index => $kpi) {
                $this->addWidget($dashboard, $tenantId, $kpi, $index, $index);
            }

            return $dashboard;
        });
    }

    /**
     * The KPIs of a role cockpit; the HR cockpit also shows the FTE headcount, which the CEO cockpit owns.
     *
     * @return list<KpiCode>
     */
    public function kpisOf(RoleCode $role): array
    {
        $kpis = array_values(array_filter(KpiCode::cases(), static fn (KpiCode $kpi): bool => $kpi->role() === $role));

        return $role === RoleCode::Hr ? [KpiCode::HeadcountFte, ...$kpis] : $kpis;
    }

    public function addWidget(KpiDashboard $dashboard, string $tenantId, KpiCode $kpi, int $sortOrder, ?int $slot = null, ?array $grid = null): KpiWidget
    {
        $slot ??= $sortOrder;

        return KpiWidget::create([
            'tenant_id' => $tenantId,
            'dashboard_id' => $dashboard->id,
            'kpi_code' => $kpi,
            'widget_type' => $kpi->widgetType(),
            'data_source_query_name' => 'GetExecutiveKpiCockpitQuery:'.$kpi->value,
            'default_time_comparison' => $kpi === KpiCode::RevenueYtdVsBudget ? ComparisonMode::Budget : ComparisonMode::Yoy,
            'threshold_rules_json' => null,
            'drill_down_route_template' => '/kpi-dashboards/drill-down/'.$kpi->value.'?period={period}&segment={segment}',
            'sort_order' => $sortOrder,
            'grid_x' => $grid['x'] ?? ($slot % 3) * 4,
            'grid_y' => $grid['y'] ?? intdiv($slot, 3) * 3,
            'grid_w' => $grid['w'] ?? 4,
            'grid_h' => $grid['h'] ?? 3,
        ]);
    }
}
