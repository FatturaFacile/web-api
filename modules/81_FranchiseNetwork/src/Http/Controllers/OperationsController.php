<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Http\Controllers\Concerns\InteractsWithFranchiseNetwork;
use Modules\FranchiseNetwork\Http\Requests\ImportSellOutRequest;
use Modules\FranchiseNetwork\Http\Requests\ListFiltersRequest;
use Modules\FranchiseNetwork\Http\Requests\LoyaltyTransactionRequest;
use Modules\FranchiseNetwork\Http\Requests\SavePurchaseRequest;
use Modules\FranchiseNetwork\Http\Requests\SaveSellOutRequest;
use Modules\FranchiseNetwork\Http\Resources\LoyaltyTransactionResource;
use Modules\FranchiseNetwork\Http\Resources\PurchaseRecordResource;
use Modules\FranchiseNetwork\Http\Resources\SellOutResource;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\LoyaltyTransaction;
use Modules\FranchiseNetwork\Models\PurchaseRecord;
use Modules\FranchiseNetwork\Models\SellOutReport;
use Modules\FranchiseNetwork\Services\BillingPeriod;
use Modules\FranchiseNetwork\Services\LoyaltyLedgerService;
use Modules\FranchiseNetwork\Services\PurchaseCommitmentService;
use Modules\FranchiseNetwork\Services\SellOutService;
use OpenApi\Attributes as OA;

/**
 * Data the billing engine reads: sell-out of the stores, purchases against the contractual minimum and the shared loyalty ledger.
 */
final class OperationsController extends Controller
{
    use ApiResponse;
    use InteractsWithFranchiseNetwork;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly SellOutService $sellOut,
        private readonly PurchaseCommitmentService $purchases,
        private readonly LoyaltyLedgerService $loyalty,
    ) {}

    #[OA\Get(path: '/api/v1/franchise-network/sell-out', operationId: 'franchiseNetworkListSellOut', summary: 'Net sell-out reported by the stores', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Sell-out reports, paginated')])]
    public function sellOutIndex(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('manage', SellOutReport::class);

        $page = SellOutReport::query()
            ->when($request->input('period'), fn ($rows, $period) => $rows->where('period', $period))
            ->when($request->input('store_id'), fn ($rows, $store) => $rows->where('store_id', $store))
            ->orderByDesc('period')
            ->paginate($this->perPage(100));

        return $this->paged($page, SellOutResource::class);
    }

    #[OA\Put(path: '/api/v1/franchise-network/stores/{id}/sell-out/{period}', operationId: 'franchiseNetworkSaveSellOut', summary: 'Record the net sell-out of a store for a month', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'period', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Sell-out saved')])]
    public function sellOutSave(SaveSellOutRequest $request, string $id, string $period): JsonResponse
    {
        $store = FranchiseStore::query()->findOrFail($id);
        Gate::authorize('update', $store);

        return $this->successResponse(new SellOutResource($this->sellOut->upsert($store, $period, $request->validated(), $this->userId())), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/sell-out/import', operationId: 'franchiseNetworkImportSellOut', summary: 'Import the sell-out of several stores for a month', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Import result')])]
    public function sellOutImport(ImportSellOutRequest $request): JsonResponse
    {
        Gate::authorize('create', SellOutReport::class);

        return $this->successResponse($this->sellOut->import((string) $request->validated('period'), $request->validated('rows'), $this->userId()), $this->message('sell_out_imported'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/purchases', operationId: 'franchiseNetworkListPurchases', summary: 'Monthly purchases of the stores against the contractual minimum', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Purchase records, paginated')])]
    public function purchasesIndex(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('manage', PurchaseRecord::class);

        $page = PurchaseRecord::query()
            ->when($request->input('period'), fn ($rows, $period) => $rows->where('period', $period))
            ->when($request->input('store_id'), fn ($rows, $store) => $rows->where('store_id', $store))
            ->orderByDesc('period')
            ->paginate($this->perPage(100));

        return $this->paged($page, PurchaseRecordResource::class);
    }

    #[OA\Put(path: '/api/v1/franchise-network/stores/{id}/purchases/{period}', operationId: 'franchiseNetworkSavePurchase', summary: 'Record the purchases of a store from the central warehouse for a month', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'period', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Purchases saved')])]
    public function purchasesSave(SavePurchaseRequest $request, string $id, string $period): JsonResponse
    {
        $store = FranchiseStore::query()->findOrFail($id);
        Gate::authorize('update', $store);
        BillingPeriod::assert($period);

        return $this->successResponse(new PurchaseRecordResource($this->purchases->record($store, $period, (string) $request->validated('purchased_amount'), $this->userId())), $this->message('saved'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/loyalty/transactions', operationId: 'franchiseNetworkListLoyalty', summary: 'Loyalty points earned and redeemed across the stores', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Loyalty transactions, paginated')])]
    public function loyaltyIndex(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('manage', LoyaltyTransaction::class);

        $page = LoyaltyTransaction::query()
            ->when($request->input('period'), fn ($rows, $period) => $rows->where('period', $period))
            ->when($request->input('store_id'), fn ($rows, $store) => $rows->where('store_id', $store))
            ->orderByDesc('occurred_at')
            ->paginate($this->perPage(50));

        return $this->paged($page, LoyaltyTransactionResource::class);
    }

    #[OA\Post(path: '/api/v1/franchise-network/loyalty/transactions', operationId: 'franchiseNetworkRecordLoyalty', summary: 'Record points earned or redeemed in a store', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 201, description: 'Transaction recorded')])]
    public function loyaltyStore(LoyaltyTransactionRequest $request): JsonResponse
    {
        Gate::authorize('create', LoyaltyTransaction::class);
        $store = FranchiseStore::query()->findOrFail($request->validated('store_id'));

        return $this->successResponse(new LoyaltyTransactionResource($this->loyalty->record($store, $request->validated(), $this->userId())), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/franchise-network/loyalty/settlement', operationId: 'franchiseNetworkLoyaltySettlement', summary: 'Net inter-store loyalty position still to be settled', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Positions by store')])]
    public function loyaltySettlement(): JsonResponse
    {
        Gate::authorize('manage', LoyaltyTransaction::class);
        $stores = FranchiseStore::query()->get()->keyBy('id');

        $rows = [];

        foreach ($this->loyalty->positions($this->period()) as $storeId => $position) {
            $rows[] = ['store_id' => $storeId, 'store_code' => $stores->get($storeId)?->code, 'store_name' => $stores->get($storeId)?->name, ...$position];
        }

        return $this->successResponse($rows, $this->message('listed'));
    }
}
