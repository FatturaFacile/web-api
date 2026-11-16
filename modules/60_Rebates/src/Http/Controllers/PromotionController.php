<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Rebates\Http\Controllers\Concerns\InteractsWithRebates;
use Modules\Rebates\Http\Requests\PromotionRequest;
use Modules\Rebates\Http\Resources\PromotionResource;
use Modules\Rebates\Models\RebatePromotion;
use OpenApi\Attributes as OA;

/**
 * Trade promotions with a date range.
 */
final class PromotionController extends Controller
{
    use InteractsWithRebates;

    #[OA\Get(path: '/api/v1/rebates/promotions', operationId: 'listRebatePromotions', description: 'Promotions, the latest start first. Filters: state (active, scheduled, expired, inactive: evaluated on the date), on (Y-m-d, default today), product_id, contact_id.', summary: 'List promotions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], responses: [
        new OA\Response(response: 200, description: 'Promotions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RebatePromotion'))])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RebatePromotion::class);
        $filters = $request->validate([
            'state' => ['nullable', 'in:active,scheduled,expired,inactive'],
            'on' => ['nullable', 'date_format:Y-m-d'],
            'product_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
        ]);
        $day = Carbon::parse((string) ($filters['on'] ?? now()->toDateString()))->startOfDay();
        $state = $filters['state'] ?? null;

        $promotions = RebatePromotion::query()
            ->where('tenant_id', $this->tenantId())
            ->when(isset($filters['product_id']), static fn ($query) => $query->where('product_id', $filters['product_id']))
            ->when(isset($filters['contact_id']), static fn ($query) => $query->where('contact_id', $filters['contact_id']))
            ->when($state === 'inactive', static fn ($query) => $query->where('is_active', false))
            ->when($state === 'active', static fn ($query) => $query->where('is_active', true)->whereDate('starts_on', '<=', $day->toDateString())->whereDate('ends_on', '>=', $day->toDateString()))
            ->when($state === 'scheduled', static fn ($query) => $query->where('is_active', true)->whereDate('starts_on', '>', $day->toDateString()))
            ->when($state === 'expired', static fn ($query) => $query->where('is_active', true)->whereDate('ends_on', '<', $day->toDateString()))
            ->orderByDesc('starts_on')
            ->orderBy('code')
            ->paginate($this->perPage());

        return PromotionResource::collection($promotions);
    }

    #[OA\Post(path: '/api/v1/rebates/promotions', operationId: 'storeRebatePromotion', description: 'Create a promotion: a percentage (up to 100) or fixed discount valid from starts_on to ends_on, optionally for one product, one customer and a minimum quantity. The code is unique in the company.', summary: 'Create a promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RebatePromotionRequest')), tags: ['Rebates'], responses: [
        new OA\Response(response: 201, description: 'Promotion created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebatePromotion')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesUnprocessable', response: 422),
    ])]
    public function store(PromotionRequest $request): JsonResponse
    {
        $this->authorize('create', RebatePromotion::class);
        $data = $request->validated();
        $this->assertCodeFree((string) $data['code'], null);

        return (new PromotionResource(RebatePromotion::create([...$data, 'tenant_id' => $this->tenantId()])->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/rebates/promotions/{id}', operationId: 'showRebatePromotion', description: 'Return a promotion.', summary: 'Show a promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 200, description: 'Promotion', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebatePromotion')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
    ])]
    public function show(string $id): PromotionResource
    {
        $promotion = RebatePromotion::query()->findOrFail($id);
        $this->authorize('view', $promotion);

        return new PromotionResource($promotion);
    }

    #[OA\Put(path: '/api/v1/rebates/promotions/{id}', operationId: 'updateRebatePromotion', description: 'Change a promotion, or switch it on and off with is_active.', summary: 'Update a promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RebatePromotionRequest')), tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 200, description: 'Promotion updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RebatePromotion')])),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/RebatesUnprocessable', response: 422),
    ])]
    public function update(PromotionRequest $request, string $id): PromotionResource
    {
        $promotion = RebatePromotion::query()->findOrFail($id);
        $this->authorize('update', $promotion);
        $data = $request->validated();

        if (isset($data['code'])) {
            $this->assertCodeFree((string) $data['code'], (string) $promotion->id);
        }

        $promotion->update($data);

        if ($promotion->ends_on->lt($promotion->starts_on)) {
            throw ValidationException::withMessages(['ends_on' => [__('rebate_errors.promotion.period')]]);
        }

        return new PromotionResource($promotion->refresh());
    }

    #[OA\Delete(path: '/api/v1/rebates/promotions/{id}', operationId: 'deleteRebatePromotion', description: 'Delete a promotion (soft delete).', summary: 'Delete a promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Rebates'], parameters: [new OA\Parameter(ref: '#/components/parameters/RebatesId')], responses: [
        new OA\Response(response: 204, description: 'Promotion deleted'),
        new OA\Response(ref: '#/components/responses/RebatesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/RebatesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/RebatesNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $promotion = RebatePromotion::query()->findOrFail($id);
        $this->authorize('delete', $promotion);
        $promotion->delete();

        return response()->noContent();
    }

    private function assertCodeFree(string $code, ?string $ignoreId): void
    {
        $taken = RebatePromotion::query()
            ->where('tenant_id', $this->tenantId())
            ->where('code', $code)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => [__('rebate_errors.promotion.code_taken', ['code' => $code])]]);
        }
    }
}
