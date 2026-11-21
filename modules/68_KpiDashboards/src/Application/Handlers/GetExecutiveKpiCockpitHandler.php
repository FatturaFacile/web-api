<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Application\Handlers;

use Modules\KpiDashboards\Application\Queries\GetExecutiveKpiCockpitQuery;
use Modules\KpiDashboards\Domain\Enums\ComparisonMode;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\Enums\RoleCode;
use Modules\KpiDashboards\Domain\Exceptions\KpiAccessDeniedException;
use Modules\KpiDashboards\Domain\Exceptions\KpiNotFoundException;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;
use Modules\KpiDashboards\Infrastructure\Models\KpiDashboard;
use Modules\KpiDashboards\Infrastructure\Models\KpiWidget;
use Modules\KpiDashboards\Infrastructure\Services\DefaultCockpitProvisioner;
use Modules\KpiDashboards\Infrastructure\Services\KpiAccess;
use Modules\KpiDashboards\Infrastructure\Services\KpiCache;
use Modules\KpiDashboards\Infrastructure\Services\KpiContext;
use Modules\KpiDashboards\Infrastructure\Services\KpiFx;
use Modules\KpiDashboards\Infrastructure\Services\KpiRegistry;
use Modules\KpiDashboards\Infrastructure\Services\ThresholdAlertEvaluator;

/**
 * Computes the payload of every widget of a cockpit under the global filters: access rules first (role, entity and cost
 * center attributes, field masking), then each KPI with its comparison against the prior period or the budget, converted
 * to the display currency at the average rate (flow) or the closing rate (stock). The result is cached per tenant, filter
 * and viewer access until the tenant's cache version changes (a posting, an order or a timesheet).
 */
final class GetExecutiveKpiCockpitHandler
{
    public function __construct(
        private readonly DefaultCockpitProvisioner $provisioner,
        private readonly KpiRegistry $registry,
        private readonly KpiFx $fx,
        private readonly KpiCache $cache,
        private readonly ThresholdAlertEvaluator $alerts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetExecutiveKpiCockpitQuery $query): array
    {
        $tenantId = $query->filter->tenantId;
        $access = KpiAccess::forUser($query->user, $tenantId);
        $dashboard = $this->resolveDashboard($query, $tenantId);

        if ($dashboard->role_code !== RoleCode::Custom && ! $access->canOpen($dashboard->role_code)) {
            throw new KpiAccessDeniedException("Your role may not open the {$dashboard->role_code->value} cockpit.");
        }

        $filter = $access->restrict($query->filter, $query->filter->eliminateIntercompany);
        $scope = "{$dashboard->id}:v{$dashboard->version}:{$access->fingerprint()}";

        return $this->cache->remember($tenantId, $scope, $filter->hash(), fn (): array => $this->compute($dashboard, $filter, $access));
    }

    private function resolveDashboard(GetExecutiveKpiCockpitQuery $query, string $tenantId): KpiDashboard
    {
        $userId = $query->user !== null ? (string) $query->user->getKey() : null;

        if ($query->dashboardId !== null) {
            $dashboard = KpiDashboard::query()->where('tenant_id', $tenantId)->whereKey($query->dashboardId)
                ->where(static fn ($where) => $where->whereNull('user_id')->orWhere('user_id', $userId))->first();

            return $dashboard ?? throw new KpiNotFoundException('Unknown dashboard.');
        }

        $role = $query->role ?? RoleCode::Ceo;
        $custom = KpiDashboard::query()->where('tenant_id', $tenantId)->where('role_code', $role->value)->where('user_id', $userId)->where('is_system_default', false)->latest('updated_at')->first();

        if ($custom instanceof KpiDashboard) {
            return $custom;
        }

        if ($role === RoleCode::Custom) {
            throw new KpiNotFoundException('You have no custom cockpit yet: save a layout to create it.');
        }

        return $this->provisioner->systemDefault($tenantId, $role);
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(KpiDashboard $dashboard, CockpitFilter $filter, KpiAccess $access): array
    {
        $widgets = [];

        foreach ($dashboard->widgets()->get() as $widget) {
            if (! $access->canSeeKpi($widget->kpi_code)) {
                continue;
            }

            $widgets[] = $this->widget($widget, $filter, $access);
        }

        return [
            'dashboard' => [
                'id' => (string) $dashboard->id,
                'role_code' => $dashboard->role_code->value,
                'title_key' => $dashboard->title_key,
                'custom_title' => $dashboard->custom_title,
                'layout_grid' => $dashboard->layout_grid_json,
                'refresh_interval_seconds' => $dashboard->refresh_interval_seconds,
                'is_system_default' => $dashboard->is_system_default,
                'version' => $dashboard->version,
            ],
            'filter' => [
                'from' => $filter->from->toDateString(),
                'to' => $filter->to->toDateString(),
                'comparison' => $filter->comparison->value,
                'company_ids' => $filter->companyIds,
                'cost_center_ids' => $filter->costCenterIds,
                'display_currency' => $filter->displayCurrency,
                'include_intercompany_eliminations' => $filter->eliminateIntercompany,
            ],
            'fx' => [
                'average' => $this->fx->rate($filter->tenantId, $filter->displayCurrency, $filter->from, $filter->to, false),
                'closing' => $this->fx->rate($filter->tenantId, $filter->displayCurrency, $filter->from, $filter->to, true),
            ],
            'widgets' => $widgets,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function widget(KpiWidget $widget, CockpitFilter $filter, KpiAccess $access): array
    {
        $kpi = $widget->kpi_code;
        $rate = $this->fx->rate($filter->tenantId, $filter->displayCurrency, $filter->from, $filter->to, $kpi->isStock());
        $context = new KpiContext($filter, $access, $kpi->isMonetary() ? $rate['rate'] : 1.0);
        $calculator = $this->registry->calculator($kpi);

        $headline = $calculator->headline($kpi, $filter);
        $data = $calculator->payload($kpi, $context);
        $prior = $this->priorHeadline($kpi, $filter);
        $convert = static fn (?float $value): ?float => $value === null ? null : ($kpi->isMonetary() ? $context->money($value) : round($value, 2));
        $value = $convert($headline);
        // Compared with the budget, the reference value is the budget of the period (already in display currency).
        $priorValue = $filter->comparison === ComparisonMode::Budget ? ($data['budget_value'] ?? null) : $convert($prior);

        $alerts = $this->alerts->evaluate($filter->tenantId, $kpi, $headline);

        return [
            'widget_id' => (string) $widget->id,
            'kpi_code' => $kpi->value,
            'role_code' => $kpi->role()->value,
            'widget_type' => $widget->widget_type->value,
            'sort_order' => $widget->sort_order,
            'position' => ['x' => $widget->grid_x, 'y' => $widget->grid_y, 'w' => $widget->grid_w, 'h' => $widget->grid_h],
            'drill_down_route_template' => $widget->drill_down_route_template,
            'source_document_type' => $kpi->sourceDocumentType(),
            'value' => $value,
            'comparison' => [
                'mode' => $filter->comparison->value,
                'prior_value' => $priorValue,
                'delta' => $value !== null && $priorValue !== null ? round($value - $priorValue, 2) : null,
                'delta_percent' => $value !== null && $priorValue !== null && $priorValue != 0.0 ? round(($value - $priorValue) / abs($priorValue) * 100, 2) : null,
            ],
            'fx_applied' => $kpi->isMonetary() ? ['kind' => $rate['kind'], 'rate' => $rate['rate'], 'available' => $rate['available']] : null,
            'alerts' => array_map(static fn ($alert): array => ['severity' => $alert->severity, 'observed_value' => (float) $alert->observed_value, 'threshold_value' => (float) $alert->threshold_value], $alerts),
            'data' => $data,
        ];
    }

    private function priorHeadline(KpiCode $kpi, CockpitFilter $filter): ?float
    {
        $period = $filter->comparison->priorPeriod($filter->from, $filter->to);

        if ($period === null) {
            return null;
        }

        return $this->registry->calculator($kpi)->headline($kpi, $filter->forPeriod($period[0], $period[1]));
    }
}
