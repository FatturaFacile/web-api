<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\VanSalesAndFieldForce\Application\Commands\IssueMorningLoadManifestCommand;
use Modules\VanSalesAndFieldForce\Application\Commands\IssueMorningLoadManifestHandler;
use Modules\VanSalesAndFieldForce\Application\Queries\GetWeekdayReplenishmentSuggestionHandler;
use Modules\VanSalesAndFieldForce\Application\Queries\GetWeekdayReplenishmentSuggestionQuery;
use Modules\VanSalesAndFieldForce\Http\Controllers\Concerns\InteractsWithVanSelling;
use Modules\VanSalesAndFieldForce\Http\Requests\VanLoadManifestStoreRequest;
use Modules\VanSalesAndFieldForce\Http\Resources\VanLoadManifestResource;
use Modules\VanSalesAndFieldForce\Models\VanLoadManifest;
use Modules\VanSalesAndFieldForce\Services\VanLoadManifestService;
use OpenApi\Attributes as OA;

final class VanLoadManifestController extends Controller
{
    use InteractsWithVanSelling;

    public function __construct(
        private readonly VanLoadManifestService $manifestService,
        private readonly IssueMorningLoadManifestHandler $issueDdtHandler,
        private readonly GetWeekdayReplenishmentSuggestionHandler $suggestionHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/van-selling/load-manifests',
        operationId: 'listVanLoadManifests',
        summary: 'List daily van morning load manifests',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Load manifests')]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VanLoadManifest::class);
        $vanId = $request->query('van_warehouse_id');

        return VanLoadManifestResource::collection(
            VanLoadManifest::query()
                ->with('vanWarehouse')
                ->where('tenant_id', $this->tenantId())
                ->when(is_string($vanId) && $vanId !== '', static fn ($q) => $q->where('van_warehouse_id', $vanId))
                ->orderByDesc('load_date')
                ->orderByDesc('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Get(
        path: '/api/v1/van-selling/load-manifests/replenishment-suggestions',
        operationId: 'getVanWeekdayReplenishmentSuggestions',
        summary: 'Get automatic morning replenishment suggestions based on historical same-weekday sales',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Replenishment suggestions')]
    )]
    public function replenishmentSuggestions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VanLoadManifest::class);
        $vanId = (string) $request->query('van_warehouse_id', '');
        $weekday = max(1, min(7, (int) $request->query('weekday_iso', now()->isoWeekday())));
        $routeCode = $request->query('route_code');

        $suggestions = $this->suggestionHandler->handle(new GetWeekdayReplenishmentSuggestionQuery(
            tenantId: $this->tenantId(),
            vanWarehouseId: $vanId,
            weekdayIso: $weekday,
            routeCode: is_string($routeCode) && $routeCode !== '' ? $routeCode : null,
        ));

        return response()->json(['data' => $suggestions]);
    }

    #[OA\Post(
        path: '/api/v1/van-selling/load-manifests',
        operationId: 'storeVanLoadManifest',
        summary: 'Create a morning van load manifest',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 201, description: 'Load manifest created')]
    )]
    public function store(VanLoadManifestStoreRequest $request): JsonResponse
    {
        $this->authorize('create', VanLoadManifest::class);
        $manifest = $this->manifestService->createManifest($this->tenantId(), $request->validated(), $this->userId());

        return (new VanLoadManifestResource($manifest->load('vanWarehouse')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/van-selling/load-manifests/{id}',
        operationId: 'showVanLoadManifest',
        summary: 'Show a morning van load manifest',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Load manifest')]
    )]
    public function show(string $id): VanLoadManifestResource
    {
        $manifest = VanLoadManifest::query()->with('vanWarehouse')->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $manifest);

        return new VanLoadManifestResource($manifest);
    }

    #[OA\Post(
        path: '/api/v1/van-selling/load-manifests/{id}/issue-global-ddt',
        operationId: 'issueGlobalVanLoadDdt',
        summary: 'Issue self-addressed Global Van Load DDT (Tentata Vendita per DPR 472/1996 & CM 249/E)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Global Van Load DDT issued')]
    )]
    public function issueGlobalDdt(string $id): VanLoadManifestResource
    {
        $manifest = VanLoadManifest::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $manifest);

        return new VanLoadManifestResource(
            $this->issueDdtHandler->handle(new IssueMorningLoadManifestCommand($this->tenantId(), (string) $manifest->id))
        );
    }
}
