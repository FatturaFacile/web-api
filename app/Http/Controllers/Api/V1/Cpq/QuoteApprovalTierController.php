<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cpq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cpq\SaveQuoteApprovalTierRequest;
use App\Http\Resources\Cpq\QuoteApprovalTierResource;
use App\Http\Responses\ApiResponse;
use App\Models\Quote;
use App\Services\Sales\Cpq\QuoteApprovalTierService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class QuoteApprovalTierController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(private readonly QuoteApprovalTierService $tiers) {}

    #[OA\Get(
        path: '/api/v1/sales/quote-approval-tiers',
        operationId: 'listSalesQuoteApprovalTiers',
        summary: 'List quote approval tiers',
        description: 'The approval ladder of the company: per level the approver role and the discount and margin limits above which a quote needs that level.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        responses: [
            new OA\Response(response: 200, description: 'Tiers', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(QuoteApprovalTierResource::collection($this->tiers->all())->resolve());
    }

    #[OA\Post(
        path: '/api/v1/sales/quote-approval-tiers',
        operationId: 'createSalesQuoteApprovalTier',
        summary: 'Create a quote approval tier',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'level', type: 'integer'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'approver_role', type: 'string'),
            new OA\Property(property: 'max_line_discount_percent', type: 'number', nullable: true),
            new OA\Property(property: 'max_global_discount_percent', type: 'number', nullable: true),
            new OA\Property(property: 'min_margin_percent', type: 'number', nullable: true),
            new OA\Property(property: 'is_active', type: 'boolean'),
        ])),
        tags: ['Quote CPQ'],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function store(SaveQuoteApprovalTierRequest $request): JsonResponse
    {
        $this->authorize('manageApprovalTiers', Quote::class);

        return $this->successResponse(new QuoteApprovalTierResource($this->tiers->create($request->validated())), __('sales.cpq.tier_saved'), Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/sales/quote-approval-tiers/{id}',
        operationId: 'updateSalesQuoteApprovalTier',
        summary: 'Update a quote approval tier',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function update(SaveQuoteApprovalTierRequest $request, string $id): JsonResponse
    {
        $this->authorize('manageApprovalTiers', Quote::class);

        return $this->successResponse(new QuoteApprovalTierResource($this->tiers->update($this->tiers->find($id), $request->validated())), __('sales.cpq.tier_saved'));
    }

    #[OA\Delete(
        path: '/api/v1/sales/quote-approval-tiers/{id}',
        operationId: 'deleteSalesQuoteApprovalTier',
        summary: 'Delete a quote approval tier',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function destroy(string $id): \Illuminate\Http\Response
    {
        $this->authorize('manageApprovalTiers', Quote::class);
        $this->tiers->delete($this->tiers->find($id));

        return response()->noContent();
    }
}
