<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Distribution\Domain\Enums\AllocationMode;
use Modules\Distribution\Domain\Enums\BillingFrequency;
use Modules\Distribution\Domain\Enums\BucketType;
use Modules\Distribution\Domain\Enums\ChannelType;
use Modules\Distribution\Domain\Enums\ProposalStatus;
use Modules\Distribution\Domain\Enums\ProposalType;
use Modules\Distribution\Domain\Enums\ReplenishmentPolicy;
use Modules\Distribution\Domain\Enums\SettlementTrigger;
use Modules\Distribution\Domain\Enums\TransportMode;
use Modules\Distribution\Domain\Enums\WarehouseRole;
use Modules\Distribution\Http\Controllers\Concerns\InteractsWithDistribution;
use Modules\Distribution\Http\Resources\DrpRunResource;
use Modules\Distribution\Models\DrpRun;
use Modules\Distribution\Models\WarehouseProfile;
use Modules\Distribution\Services\DashboardService;
use Modules\Distribution\Services\NetworkService;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use OpenApi\Attributes as OA;

/**
 * Lookups (enumerations, warehouses, items) and the KPIs of the distribution overview.
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use InteractsWithDistribution;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/distribution/lookups', operationId: 'distributionLookups', summary: 'Enumerations, warehouses and items of the distribution planning', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Lookup lists')])]
    public function lookups(NetworkService $network): JsonResponse
    {
        Gate::authorize('viewAny', WarehouseProfile::class);

        $warehouses = Warehouse::query()->where('is_active', true)->orderBy('code')->get();
        $roles = $network->roles($warehouses);

        return $this->successResponse([
            'warehouse_roles' => WarehouseRole::values(),
            'replenishment_policies' => ReplenishmentPolicy::values(),
            'allocation_modes' => AllocationMode::values(),
            'proposal_types' => ProposalType::values(),
            'proposal_statuses' => ProposalStatus::values(),
            'transport_modes' => TransportMode::values(),
            'bucket_types' => BucketType::values(),
            'channel_types' => ChannelType::values(),
            'settlement_triggers' => SettlementTrigger::values(),
            'billing_frequencies' => BillingFrequency::values(),
            'warehouses' => $warehouses->map(static fn (Warehouse $warehouse): array => [
                'id' => (string) $warehouse->id,
                'code' => (string) $warehouse->code,
                'name' => (string) $warehouse->name,
                'role' => $roles[(string) $warehouse->id]->value,
            ])->values()->all(),
            'items' => StockItem::query()->where('is_active', true)->orderBy('sku')->limit(1000)->get()->map(static fn (StockItem $item): array => [
                'id' => (string) $item->id,
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'uom' => (string) $item->uom,
            ])->values()->all(),
        ], $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/distribution/dashboard', operationId: 'distributionDashboard', summary: 'KPIs of the distribution overview', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'KPIs and overdue shipments')])]
    public function dashboard(DashboardService $dashboard): JsonResponse
    {
        Gate::authorize('viewAny', DrpRun::class);

        $overview = $dashboard->overview();
        $overview['latest_run'] = $overview['latest_run'] === null ? null : new DrpRunResource($overview['latest_run']);

        return $this->successResponse($overview, $this->message('loaded'));
    }
}
