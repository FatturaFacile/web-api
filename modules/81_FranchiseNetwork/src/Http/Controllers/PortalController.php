<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Domain\Support\Dec;
use Modules\FranchiseNetwork\Domain\Support\LocalizedText;
use Modules\FranchiseNetwork\Http\Controllers\Concerns\InteractsWithFranchiseNetwork;
use Modules\FranchiseNetwork\Http\Requests\RedeemLinkCodeRequest;
use Modules\FranchiseNetwork\Http\Resources\AgreementResource;
use Modules\FranchiseNetwork\Http\Resources\FormulaResource;
use Modules\FranchiseNetwork\Http\Resources\PurchaseRecordResource;
use Modules\FranchiseNetwork\Http\Resources\SellOutResource;
use Modules\FranchiseNetwork\Http\Resources\StatementResource;
use Modules\FranchiseNetwork\Http\Resources\StoreResource;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Services\AgreementService;
use Modules\FranchiseNetwork\Services\BenchmarkService;
use Modules\FranchiseNetwork\Services\CatalogPushService;
use Modules\FranchiseNetwork\Services\NetworkAccess;
use Modules\FranchiseNetwork\Services\PortalQueries;
use Modules\FranchiseNetwork\Services\StoreService;
use OpenApi\Attributes as OA;

/**
 * Franchisee portal: the company reads what the franchisor governs about its own store (contract, obligations, syncs received,
 * statements, anonymous benchmark) and acknowledges the pushes. It never reads the books of another franchisee, nor any data of the
 * franchisor beyond the rows of its own store.
 */
final class PortalController extends Controller
{
    use ApiResponse;
    use InteractsWithFranchiseNetwork;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly NetworkAccess $access,
        private readonly PortalQueries $queries,
        private readonly StoreService $stores,
        private readonly AgreementService $agreements,
        private readonly CatalogPushService $pushes,
        private readonly BenchmarkService $benchmark,
    ) {}

    #[OA\Get(path: '/api/v1/franchise-network/portal/me', operationId: 'franchiseNetworkPortalMe', summary: 'Stores the active company is linked to as a franchisee', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Linked stores')])]
    public function me(): JsonResponse
    {
        Gate::authorize('portal', FranchiseStore::class);

        return $this->successResponse(['stores' => StoreResource::collection($this->access->portalStores())], $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/portal/link', operationId: 'franchiseNetworkPortalLink', summary: 'Link the active company to a store with the code issued by the franchisor', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Company linked')])]
    public function link(RedeemLinkCodeRequest $request): JsonResponse
    {
        Gate::authorize('portalWrite', FranchiseStore::class);

        return $this->successResponse(new StoreResource($this->stores->redeemLinkCode((string) $request->validated('code'))), $this->message('portal_linked'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/portal/contract', operationId: 'franchiseNetworkPortalContract', summary: 'Contract summary, exclusivity zone, obligations and the fee formula of the store', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Contract')])]
    public function contract(): JsonResponse
    {
        Gate::authorize('portal', FranchiseStore::class);
        $store = $this->access->portalStore(request()->query('store_id'));
        $agreement = $this->queries->agreement($store);

        if ($agreement === null) {
            return $this->successResponse(['store' => new StoreResource($store), 'agreement' => null, 'formula' => null, 'purchases' => []], $this->message('loaded'));
        }

        $formula = $this->queries->formula($agreement);
        $purchases = $this->queries->purchases($store);

        return $this->successResponse([
            'store' => new StoreResource($store),
            'agreement' => new AgreementResource($agreement, $this->agreements->findings($agreement), $this->agreements->alerts($agreement)),
            'formula' => $formula === null ? null : new FormulaResource($formula),
            'purchases' => PurchaseRecordResource::collection($purchases),
            'purchase_compliance' => [
                'months' => $purchases->count(),
                'compliant_months' => $purchases->where('compliant', true)->count(),
                'shortfall' => Dec::round($purchases->reduce(static fn (string $sum, $row): string => Dec::add($sum, Dec::max(Dec::sub($row->required_amount, $row->purchased_amount), 0)), '0')),
            ],
        ], $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/portal/syncs', operationId: 'franchiseNetworkPortalSyncs', summary: 'Catalogue, price, promotion and loyalty pushes received by the store', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Syncs')])]
    public function syncs(): JsonResponse
    {
        Gate::authorize('portal', FranchiseStore::class);
        $store = $this->access->portalStore(request()->query('store_id'));

        return $this->successResponse($this->queries->syncs($store)->map(fn ($target): array => [
            'id' => $target->id,
            'status' => $target->status->value,
            'synced_at' => $target->synced_at?->toIso8601String(),
            'acknowledged_at' => $target->acknowledged_at?->toIso8601String(),
            'push' => $target->catalogPush === null ? null : [
                'id' => $target->catalogPush->id,
                'push_type' => $target->catalogPush->push_type->value,
                'title' => $target->catalogPush->title,
                'effective_from' => $target->catalogPush->effective_from?->toDateString(),
                'payload' => $target->status->value === 'pending' || $target->status->value === 'failed' ? null : $this->pushes->payloadFor($target->catalogPush, $store),
            ],
        ])->all(), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/portal/syncs/{id}/acknowledge', operationId: 'franchiseNetworkPortalAcknowledge', summary: 'Confirm that a received push was applied', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Push acknowledged')])]
    public function acknowledge(string $id): JsonResponse
    {
        Gate::authorize('portalWrite', FranchiseStore::class);
        $store = $this->access->portalStore(request()->query('store_id'));
        $target = $this->pushes->acknowledge($this->queries->target($store, $id));

        return $this->successResponse(['id' => $target->id, 'status' => $target->status->value, 'acknowledged_at' => $target->acknowledged_at?->toIso8601String()], $this->message('push_acknowledged'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/portal/statements', operationId: 'franchiseNetworkPortalStatements', summary: 'Monthly sell-out and invoiced royalty and marketing fund statements of the store', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Statements')])]
    public function statements(): JsonResponse
    {
        Gate::authorize('portal', FranchiseStore::class);
        $store = $this->access->portalStore(request()->query('store_id'));

        return $this->successResponse([
            'statements' => StatementResource::collection($this->queries->statements($store)),
            'sell_out' => SellOutResource::collection($this->queries->sellOut($store)),
        ], $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/portal/benchmark', operationId: 'franchiseNetworkPortalBenchmark', summary: 'Anonymous benchmark of the store against its cluster and the network', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Benchmark')])]
    public function benchmark(): JsonResponse
    {
        Gate::authorize('portal', FranchiseStore::class);
        $store = $this->access->portalStore(request()->query('store_id'));
        $period = request()->query('period') ?: $this->benchmark->latestPeriod($store);
        $locale = app()->getLocale();

        if ($period === null) {
            return $this->successResponse(['period' => null, 'cluster' => $store->size_cluster->value, 'cluster_hidden' => false, 'rows' => []], $this->message('loaded'));
        }

        $comparison = $this->benchmark->comparison($store, (string) $period);
        $comparison['rows'] = array_map(static fn (array $row): array => [
            'code' => $row['dimension']->code,
            'label' => LocalizedText::pick($row['dimension']->labels, $locale),
            'description' => LocalizedText::pick($row['dimension']->descriptions, $locale),
            'unit' => $row['dimension']->unit,
            'higher_is_better' => $row['dimension']->higher_is_better,
            'own' => $row['own'], 'network' => $row['network'], 'cluster' => $row['cluster'], 'position' => $row['position'],
        ], $comparison['rows']);

        return $this->successResponse($comparison, $this->message('loaded'));
    }
}
