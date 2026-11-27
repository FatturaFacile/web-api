<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GreenCare\Application\Services\DashboardService;
use Modules\GreenCare\Domain\Compliance\ComplianceStrategyResolver;
use Modules\GreenCare\Domain\Enums\BillingMode;
use Modules\GreenCare\Domain\Enums\BillingStatus;
use Modules\GreenCare\Domain\Enums\ContractStatus;
use Modules\GreenCare\Domain\Enums\DosageUnit;
use Modules\GreenCare\Domain\Enums\EquipmentType;
use Modules\GreenCare\Domain\Enums\InstallmentFrequency;
use Modules\GreenCare\Domain\Enums\LicenseType;
use Modules\GreenCare\Domain\Enums\RouteStatus;
use Modules\GreenCare\Domain\Enums\ServiceType;
use Modules\GreenCare\Domain\Enums\WeatherAlertStatus;
use Modules\GreenCare\Http\Controllers\Concerns\InteractsWithGreenCare;
use Modules\GreenCare\Models\GreenSpaceSite;
use OpenApi\Attributes as OA;

/**
 * KPI cards of the dashboard and the reference lists of the module.
 */
final class DashboardController extends Controller
{
    use ApiResponse;
    use InteractsWithGreenCare;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/green-care/dashboard', operationId: 'greenCareDashboard', summary: 'KPI cards: contracts, passages, weather-impacted routes, treatments, green waste, expiring licenses', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Dashboard')])]
    public function dashboard(DashboardService $dashboard): JsonResponse
    {
        Gate::authorize('viewAny', GreenSpaceSite::class);

        return $this->successResponse($dashboard->summary((int) request()->query('year', (string) now()->year)), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/green-care/reference', operationId: 'greenCareReference', summary: 'Enumerations, license types, waste codes and registration number hints by jurisdiction', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Reference data')])]
    public function reference(ComplianceStrategyResolver $strategies): JsonResponse
    {
        Gate::authorize('viewAny', GreenSpaceSite::class);

        $licenseTypes = [];
        $wasteCodes = [];
        $hints = [];
        $botanical = [];

        foreach (ComplianceStrategyResolver::JURISDICTIONS as $jurisdiction) {
            $strategy = $strategies->for($jurisdiction);
            $licenseTypes[$jurisdiction] = array_map(static fn (LicenseType $type): string => $type->value, $strategy->acceptedLicenseTypes());
            $wasteCodes[$jurisdiction] = $strategy->wasteCodes();
            $hints[$jurisdiction] = $strategy->registrationNumberHint();
            $botanical[$jurisdiction] = $strategy->botanicalCatalog();
        }

        return $this->successResponse([
            'jurisdictions' => ComplianceStrategyResolver::JURISDICTIONS,
            'billing_modes' => BillingMode::values(),
            'installment_frequencies' => InstallmentFrequency::values(),
            'contract_statuses' => ContractStatus::values(),
            'route_statuses' => RouteStatus::values(),
            'weather_alert_statuses' => WeatherAlertStatus::values(),
            'billing_statuses' => BillingStatus::values(),
            'license_types' => $licenseTypes,
            'dosage_units' => DosageUnit::values(),
            'equipment_types' => EquipmentType::values(),
            'services' => ServiceType::values(),
            'waste_codes' => $wasteCodes,
            'registration_number_hint' => $hints,
            'botanical_catalog' => $botanical,
            'condominium_withholding_percent' => (float) config('green_care.condominium_withholding.percent', 4),
        ], $this->message('loaded'));
    }
}
