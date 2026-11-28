<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\BondedWarehouses\Application\Queries\DashboardQueries;
use Modules\BondedWarehouses\Application\Queries\RegistryQueries;
use Modules\BondedWarehouses\Http\Controllers\Concerns\InteractsWithBondedWarehouses;
use Modules\BondedWarehouses\Http\Resources\CguLedgerResource;
use Modules\BondedWarehouses\Models\CguLedgerEntry;
use OpenApi\Attributes as OA;

/**
 * Real-time plafond of the comprehensive guarantee (CGU) and its append-only ledger.
 */
final class CguController extends Controller
{
    use ApiResponse;
    use InteractsWithBondedWarehouses;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/bonded-warehouses/cgu/plafond', operationId: 'bondedWarehousesCguPlafond', summary: 'Ceiling, utilized amount and available plafond of every authorization', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Plafond')])]
    public function plafond(DashboardQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', CguLedgerEntry::class);

        return $this->successResponse($queries->plafond(), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/bonded-warehouses/cgu/ledger', operationId: 'bondedWarehousesCguLedger', summary: 'CGU engagement and release ledger', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'CGU ledger')])]
    public function ledger(RegistryQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', CguLedgerEntry::class);

        return $this->paged($queries->cguLedger($this->filters(), $this->perPage()), CguLedgerResource::class);
    }
}
