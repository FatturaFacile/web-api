<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GdoTradeTerms\Application\Commands\SavePromotionCommand;
use Modules\GdoTradeTerms\Application\Commands\SavePromotionHandler;
use Modules\GdoTradeTerms\Application\Commands\TransitionPromotionCommand;
use Modules\GdoTradeTerms\Application\Commands\TransitionPromotionHandler;
use Modules\GdoTradeTerms\Http\Controllers\Concerns\InteractsWithGdo;
use Modules\GdoTradeTerms\Http\Requests\ListFiltersRequest;
use Modules\GdoTradeTerms\Http\Requests\SavePromotionRequest;
use Modules\GdoTradeTerms\Http\Requests\TransitionRequest;
use Modules\GdoTradeTerms\Http\Resources\PromotionResource;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoPromotion;
use Modules\GdoTradeTerms\Services\PromotionService;
use OpenApi\Attributes as OA;

/**
 * Trade promotions and contributions (flyer, sell-out, opening, listing, display, Ce.Di. logistics) of the contracts.
 */
final class PromotionController extends Controller
{
    use ApiResponse;
    use InteractsWithGdo;

    #[OA\Get(path: '/api/v1/gdo-trade-terms/promotions', operationId: 'gdoTradeTermsListPromotions', summary: 'List trade promotions of the company', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], responses: [new OA\Response(response: 200, description: 'Promotions, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', GdoPromotion::class);

        $page = GdoPromotion::query()
            ->with('contract:id,code,name')
            ->when($request->input('contract_id'), fn ($rows, $contractId) => $rows->where('contract_id', $contractId))
            ->when($request->input('status'), fn ($rows, $status) => $rows->where('status', $status))
            ->when($request->input('q'), function ($rows, $term): void {
                $like = '%'.mb_strtolower((string) $term).'%';
                $rows->where(fn ($inner) => $inner->whereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like]));
            })
            ->orderByDesc('starts_on')
            ->orderBy('code')
            ->paginate($this->perPage());

        return $this->paged($page, PromotionResource::class);
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/contracts/{id}/promotions', operationId: 'gdoTradeTermsCreatePromotion', summary: 'Add a trade promotion to a contract', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Promotion created')])]
    public function store(SavePromotionRequest $request, string $id, SavePromotionHandler $handler): JsonResponse
    {
        Gate::authorize('update', GdoContract::query()->findOrFail($id));

        return $this->successResponse(new PromotionResource($handler->handle(new SavePromotionCommand($id, $request->validated(), $this->userId()))), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/gdo-trade-terms/contracts/{id}/promotions/{promotionId}', operationId: 'gdoTradeTermsUpdatePromotion', summary: 'Update a trade promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'promotionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Promotion updated')])]
    public function update(SavePromotionRequest $request, string $id, string $promotionId, SavePromotionHandler $handler): JsonResponse
    {
        Gate::authorize('update', GdoContract::query()->findOrFail($id));

        return $this->successResponse(new PromotionResource($handler->handle(new SavePromotionCommand($id, $request->validated(), $this->userId(), $promotionId))), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/gdo-trade-terms/contracts/{id}/promotions/{promotionId}/transition', operationId: 'gdoTradeTermsTransitionPromotion', summary: 'Activate, end or cancel a promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'promotionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Promotion moved')])]
    public function transition(TransitionRequest $request, string $id, string $promotionId, TransitionPromotionHandler $handler): JsonResponse
    {
        Gate::authorize('update', GdoContract::query()->findOrFail($id));
        abort_unless(GdoPromotion::query()->where('contract_id', $id)->whereKey($promotionId)->exists(), 404);

        return $this->successResponse(new PromotionResource($handler->handle(new TransitionPromotionCommand($promotionId, (string) $request->validated('action'), $this->userId()))), $this->message('transitioned'));
    }

    #[OA\Delete(path: '/api/v1/gdo-trade-terms/contracts/{id}/promotions/{promotionId}', operationId: 'gdoTradeTermsDeletePromotion', summary: 'Delete a draft promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['GDO Trade Terms'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'promotionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Promotion deleted')])]
    public function destroy(string $id, string $promotionId, PromotionService $promotions): JsonResponse
    {
        Gate::authorize('update', GdoContract::query()->findOrFail($id));
        $promotions->delete(GdoPromotion::query()->where('contract_id', $id)->findOrFail($promotionId), $this->userId());

        return $this->successResponse(null, $this->message('deleted'));
    }
}
