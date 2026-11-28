<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\OliveMillAndGrainMill\Application\Queries\DashboardQueries;
use Modules\OliveMillAndGrainMill\Domain\Enums\BottleFormat;
use Modules\OliveMillAndGrainMill\Domain\Enums\ByproductType;
use Modules\OliveMillAndGrainMill\Domain\Enums\Certification;
use Modules\OliveMillAndGrainMill\Domain\Enums\Commodity;
use Modules\OliveMillAndGrainMill\Domain\Enums\CycleStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\OilCategory;
use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;
use Modules\OliveMillAndGrainMill\Domain\Enums\ProcessingMode;
use Modules\OliveMillAndGrainMill\Domain\Enums\SettlementMode;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianCausalCode;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\TariffMode;
use Modules\OliveMillAndGrainMill\Http\Controllers\Concerns\InteractsWithOliveMill;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;
use OpenApi\Attributes as OA;

/**
 * Campaign dashboard (control tower) and the codes the screens build their selects from.
 */
final class OverviewController extends Controller
{
    use ApiResponse;
    use InteractsWithOliveMill;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/olive-mill/mill-dashboard/stats', operationId: 'oliveMillDashboardStats', summary: 'Campaign KPIs: crop milled, yield, oil by category, molenda stock, SIAN deadlines, hoppers', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Statistics')])]
    public function stats(Request $request, DashboardQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', OliveDelivery::class);

        $year = $request->query('campaign_year');

        return $this->successResponse($queries->stats(is_numeric($year) ? (int) $year : null), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/olive-mill/reference', operationId: 'oliveMillReference', summary: 'Codes of the enumerations and the technical constants of the module', security: self::SECURITY, tags: ['Olive mill'], responses: [new OA\Response(response: 200, description: 'Reference data')])]
    public function reference(): JsonResponse
    {
        Gate::authorize('viewAny', OliveDelivery::class);

        return $this->successResponse([
            'processing_modes' => ProcessingMode::values(),
            'commodities' => Commodity::values(),
            'certifications' => Certification::values(),
            'oil_categories' => OilCategory::values(),
            'origin_designations' => OriginDesignation::values(),
            'delivery_statuses' => DeliveryStatus::values(),
            'cycle_statuses' => CycleStatus::values(),
            'sian_causals' => SianCausalCode::values(),
            'sian_statuses' => SianStatus::values(),
            'tariff_modes' => TariffMode::values(),
            'settlement_modes' => SettlementMode::values(),
            'byproduct_types' => ByproductType::values(),
            'bottle_formats' => BottleFormat::values(),
            'constants' => [
                'density_kg_per_l' => (float) config('olive_mill.density_kg_per_l'),
                'cold_extraction_max_c' => (float) config('olive_mill.cold_extraction_max_c'),
                'sian_deadline_days' => (int) config('olive_mill.sian_deadline_days'),
                'sian_alert_days' => (int) config('olive_mill.sian_alert_days'),
                'sms_lead_minutes' => (int) config('olive_mill.sms_lead_minutes'),
                'vegetation_water_max_m3_per_ha' => (float) config('olive_mill.vegetation_water_max_m3_per_ha'),
                'default_service_vat_rate' => (float) config('olive_mill.vat.milling_service'),
                'default_oil_purchase_vat_rate' => (float) config('olive_mill.vat.oil_purchase'),
            ],
        ], $this->message('loaded'));
    }
}
