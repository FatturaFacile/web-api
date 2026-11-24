<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\KpiDashboards\Application\Commands\SaveCustomDashboardLayoutCommand;
use Modules\KpiDashboards\Domain\Enums\ComparisonMode;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\Enums\RoleCode;
use Modules\KpiDashboards\Domain\Enums\WidgetType;
use Modules\KpiDashboards\Domain\Exceptions\KpiAccessDeniedException;
use Modules\KpiDashboards\Domain\Exceptions\KpiDashboardException;
use Modules\KpiDashboards\Domain\Exceptions\KpiNotFoundException;
use Modules\KpiDashboards\Infrastructure\Models\KpiDashboard;
use Modules\KpiDashboards\Infrastructure\Services\DefaultCockpitProvisioner;
use Modules\KpiDashboards\Infrastructure\Services\KpiAccess;
use Modules\KpiDashboards\Infrastructure\Services\KpiCache;

final class SaveCustomDashboardLayoutHandler
{
    public const int MinRefreshSeconds = 30;

    public function __construct(
        private readonly DefaultCockpitProvisioner $provisioner,
        private readonly KpiCache $cache,
    ) {}

    public function handle(SaveCustomDashboardLayoutCommand $command): KpiDashboard
    {
        $this->validate($command);
        $userId = (string) $command->user->getKey();

        $dashboard = DB::transaction(function () use ($command, $userId): KpiDashboard {
            $existing = $command->dashboardId !== null
                ? KpiDashboard::query()->where('tenant_id', $command->tenantId)->whereKey($command->dashboardId)->first()
                : null;

            if ($command->dashboardId !== null && ! $existing instanceof KpiDashboard) {
                throw new KpiNotFoundException('Unknown dashboard.');
            }

            // A system default is never edited: saving it creates the user's own copy.
            if ($existing instanceof KpiDashboard && ! $existing->is_system_default && $existing->user_id === $userId) {
                if ($command->expectedVersion !== null && $command->expectedVersion !== $existing->version) {
                    throw new KpiDashboardException('The cockpit was changed elsewhere: reload it before saving.');
                }

                $existing->widgets()->get()->each->delete();
                $existing->update([
                    'custom_title' => $command->customTitle ?? $existing->custom_title,
                    'refresh_interval_seconds' => $command->refreshIntervalSeconds,
                    'version' => $existing->version + 1,
                ]);

                return $existing;
            }

            if ($existing instanceof KpiDashboard && $existing->user_id !== null && $existing->user_id !== $userId) {
                throw new KpiNotFoundException('Unknown dashboard.');
            }

            return KpiDashboard::create([
                'tenant_id' => $command->tenantId,
                'user_id' => $userId,
                'role_code' => $existing instanceof KpiDashboard ? $existing->role_code : $command->role,
                'title_key' => $existing instanceof KpiDashboard ? $existing->title_key : 'KPI_DASHBOARDS.DASHBOARD_CUSTOM',
                'custom_title' => $command->customTitle,
                'layout_grid_json' => ['columns' => DefaultCockpitProvisioner::Columns, 'row_height' => 80, 'compact' => 'vertical'],
                'refresh_interval_seconds' => $command->refreshIntervalSeconds,
                'is_system_default' => false,
                'version' => 1,
            ]);
        });

        DB::transaction(function () use ($command, $dashboard): void {
            foreach ($command->widgets as $index => $item) {
                $kpi = KpiCode::from($item['kpi_code']);
                $widget = $this->provisioner->addWidget($dashboard, $command->tenantId, $kpi, $index, null, ['x' => $item['x'], 'y' => $item['y'], 'w' => $item['w'], 'h' => $item['h']]);
                $widget->update([
                    'widget_type' => isset($item['widget_type']) ? WidgetType::from($item['widget_type']) : $kpi->widgetType(),
                    'default_time_comparison' => isset($item['default_time_comparison']) ? ComparisonMode::from($item['default_time_comparison']) : $widget->default_time_comparison,
                    'threshold_rules_json' => $item['threshold_rules'] ?? null,
                ]);
            }
        });

        $this->cache->invalidate($command->tenantId);

        return $dashboard->load('widgets');
    }

    private function validate(SaveCustomDashboardLayoutCommand $command): void
    {
        if ($command->refreshIntervalSeconds < self::MinRefreshSeconds) {
            throw new KpiDashboardException('The refresh interval is at least '.self::MinRefreshSeconds.' seconds.');
        }

        if ($command->widgets === []) {
            throw new KpiDashboardException('A cockpit needs at least one widget.');
        }

        $access = KpiAccess::forUser($command->user, $command->tenantId);
        $seen = [];

        foreach ($command->widgets as $item) {
            $kpi = KpiCode::tryFrom($item['kpi_code']) ?? throw new KpiDashboardException("Unknown KPI [{$item['kpi_code']}].");

            if (isset($seen[$kpi->value])) {
                throw new KpiDashboardException("The KPI [{$kpi->value}] is placed twice.");
            }

            $seen[$kpi->value] = true;

            if (! $access->canSeeKpi($kpi)) {
                throw new KpiAccessDeniedException("Your role may not place the KPI [{$kpi->value}].");
            }

            if ($item['x'] < 0 || $item['y'] < 0 || $item['w'] < 1 || $item['h'] < 1 || $item['x'] + $item['w'] > DefaultCockpitProvisioner::Columns) {
                throw new KpiDashboardException("The widget [{$kpi->value}] does not fit the ".DefaultCockpitProvisioner::Columns.'-column grid.');
            }
        }

        if ($command->role === RoleCode::Custom && $command->dashboardId === null && ! $access->canOpen(RoleCode::Custom)) {
            throw new KpiAccessDeniedException('Your role may not create a custom cockpit.');
        }
    }
}
