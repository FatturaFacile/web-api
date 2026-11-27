<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use Modules\GreenCare\Domain\Enums\BillingStatus;
use Modules\GreenCare\Domain\Enums\ContractStatus;
use Modules\GreenCare\Domain\Enums\RouteStatus;
use Modules\GreenCare\Domain\Enums\WeatherAlertStatus;
use Modules\GreenCare\Models\CrewRouteSchedule;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\GreenWasteSlip;
use Modules\GreenCare\Models\OperatorPhytosanitaryLicense;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;

/**
 * The KPI cards of the green care dashboard: the contracts, the passages done against the passages sold, the routes hit by the weather,
 * the treatments and the green waste of the year, and the operator licenses that are about to expire.
 */
final class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(int $year): array
    {
        $today = now()->startOfDay();
        $active = SeasonalMaintenanceContract::query()->where('status', ContractStatus::Active->value);
        $alertDays = (int) config('green_care.license_expiry_alert_days', 60);

        $licenses = OperatorPhytosanitaryLicense::query()
            ->with('operator')
            ->where('is_active', true)
            ->whereDate('expiry_date', '<=', $today->copy()->addDays($alertDays)->toDateString())
            ->orderBy('expiry_date')
            ->get();

        return [
            'active_contracts' => (clone $active)->count(),
            'passages_executed' => (int) (clone $active)->sum('completed_passages_count'),
            'passages_contracted' => (int) (clone $active)->sum('included_passages_count'),
            'extra_passages_to_invoice' => CrewRouteStop::query()->where('is_extra_passage', true)->where('billing_status', BillingStatus::ReadyToInvoice->value)->whereNull('invoice_id')->count(),
            'weather_impacted_routes' => CrewRouteSchedule::query()
                ->whereDate('schedule_date', '>=', $today->toDateString())
                ->whereIn('weather_alert_status', [WeatherAlertStatus::RainWarning->value, WeatherAlertStatus::FrostAlert->value, WeatherAlertStatus::RescheduledDueToWeather->value])
                ->count(),
            'upcoming_routes' => CrewRouteSchedule::query()->whereDate('schedule_date', '>=', $today->toDateString())->where('status', RouteStatus::Planned->value)->count(),
            'treatments_logged' => PhytosanitaryTreatmentLog::query()->whereYear('treatment_date', $year)->count(),
            'green_waste_kg' => round((float) GreenWasteSlip::query()->whereYear('disposal_date', $year)->sum('green_waste_weight_kg'), 2),
            'expiring_licenses' => $licenses->map(static fn (OperatorPhytosanitaryLicense $license): array => [
                'license_id' => $license->id,
                'operator_name' => (string) ($license->operator->name ?? '-'),
                'license_type' => $license->license_type->value,
                'expiry_date' => $license->expiry_date->format('Y-m-d'),
                'days_to_expiry' => (int) $today->diffInDays($license->expiry_date, false),
            ])->values()->all(),
        ];
    }
}
