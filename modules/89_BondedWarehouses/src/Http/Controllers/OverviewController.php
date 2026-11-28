<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\BondedWarehouses\Application\Queries\DashboardQueries;
use Modules\BondedWarehouses\Domain\Enums\AeoStatus;
use Modules\BondedWarehouses\Domain\Enums\AllocationMethod;
use Modules\BondedWarehouses\Domain\Enums\AuditType;
use Modules\BondedWarehouses\Domain\Enums\CguDirection;
use Modules\BondedWarehouses\Domain\Enums\CguReferenceType;
use Modules\BondedWarehouses\Domain\Enums\CustomsStatus;
use Modules\BondedWarehouses\Domain\Enums\DeclarationType;
use Modules\BondedWarehouses\Domain\Enums\DischargeType;
use Modules\BondedWarehouses\Domain\Enums\DiscrepancyStatus;
use Modules\BondedWarehouses\Domain\Enums\RegimeType;
use Modules\BondedWarehouses\Domain\Enums\SdiDocumentType;
use Modules\BondedWarehouses\Domain\Enums\UsualHandlingOperation;
use Modules\BondedWarehouses\Domain\Enums\VatDepotMovementType;
use Modules\BondedWarehouses\Domain\Enums\WarehouseSubtype;
use Modules\BondedWarehouses\Http\Controllers\Concerns\InteractsWithBondedWarehouses;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;
use OpenApi\Attributes as OA;

/**
 * Overview dashboard (CGU plafond monitor, KPI cards) and the codes the screens build their selects from.
 */
final class OverviewController extends Controller
{
    use ApiResponse;
    use InteractsWithBondedWarehouses;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/bonded-warehouses/dashboard', operationId: 'bondedWarehousesDashboard', summary: 'CGU plafond, expiring authorizations and AEO score', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Dashboard')])]
    public function dashboard(DashboardQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', CustomsWarehouseAuthorization::class);

        return $this->successResponse($queries->overview(), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/bonded-warehouses/reference', operationId: 'bondedWarehousesReference', summary: 'Codes of the enumerations of the module', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Reference data')])]
    public function reference(): JsonResponse
    {
        Gate::authorize('viewAny', CustomsWarehouseAuthorization::class);

        return $this->successResponse([
            'regime_types' => RegimeType::values(),
            'warehouse_subtypes' => WarehouseSubtype::values(),
            'aeo_statuses' => AeoStatus::values(),
            'declaration_types' => DeclarationType::values(),
            'customs_statuses' => CustomsStatus::values(),
            'discharge_types' => DischargeType::values(),
            'allocation_methods' => AllocationMethod::values(),
            'usual_handling_operations' => UsualHandlingOperation::values(),
            'cgu_reference_types' => CguReferenceType::values(),
            'cgu_directions' => CguDirection::values(),
            'vat_movement_types' => VatDepotMovementType::values(),
            'sdi_document_types' => SdiDocumentType::values(),
            'audit_types' => AuditType::values(),
            'discrepancy_statuses' => DiscrepancyStatus::values(),
            'currencies' => ['EUR', 'CHF'],
        ], $this->message('loaded'));
    }
}
