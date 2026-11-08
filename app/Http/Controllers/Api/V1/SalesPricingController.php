<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesPriceRequest;
use App\Http\Requests\Sales\StoreDiscountPolicyRequest;
use App\Http\Resources\DiscountPolicyResource;
use App\Http\Responses\ApiResponse;
use App\Services\Sales\DiscountPolicyService;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Models\DiscountPolicy;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Price determination and promotions of the sales cycle: the price a product gets for a customer, a quantity and a
 * date, and the discount policies (promotions) that build it.
 */
class SalesPricingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DiscountPolicyService $policies) {}

    /**
     * Price of a product for a customer.
     */
    #[OA\Get(
        path: '/api/v1/sales/pricing/price',
        operationId: 'determineSalesPrice',
        description: 'Runs the price determination of the sales documents: list price from the price list given, else the price list of the customer, else the default list, else the product price (validity periods and quantity breaks apply); then the discount policy given plus every active auto-apply promotion valid on the date, by priority (a non-stackable promotion applies only when first and stops the others). Returns the unit price and discount percentage a document line would get, with the discounts applied.',
        summary: 'Determine sales price',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Catalog', 'Pricing'],
        parameters: [
            new OA\Parameter(name: 'product_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, description: 'Customer', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'quantity', in: 'query', required: false, description: 'Line quantity (1 when omitted)', schema: new OA\Schema(type: 'number', example: 10)),
            new OA\Parameter(name: 'date', in: 'query', required: false, description: 'Document date (today when omitted)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'price_list_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'discount_policy_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'order_amount', in: 'query', required: false, description: 'Order amount, for the promotions with a minimum order amount', schema: new OA\Schema(type: 'number')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Price determined', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCyclePriceDetermination'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function price(SalesPriceRequest $request, PricingAndDiscountServiceInterface $pricing): JsonResponse
    {
        $data = $request->validated();

        $price = $pricing->determineLinePrice(
            (string) $data['product_id'],
            DocumentTotalsCalculator::decimal($data['quantity'] ?? '1'),
            $data['contact_id'] ?? null,
            $data['price_list_id'] ?? null,
            $data['discount_policy_id'] ?? null,
            $data['date'] ?? null,
            isset($data['order_amount']) ? DocumentTotalsCalculator::decimal($data['order_amount']) : null,
        );

        return $this->successResponse($price, __('sales.messages.price_determined'));
    }

    /**
     * The discount policies of the company.
     */
    #[OA\Get(
        path: '/api/v1/sales/discount-policies',
        operationId: 'listSalesDiscountPolicies',
        description: 'Lists the discount policies and promotions of the active company by priority, with their rules.',
        summary: 'List discount policies',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Catalog', 'Pricing'],
        responses: [
            new OA\Response(response: 200, description: 'Discount policies', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesCycleDiscountPolicy')),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(DiscountPolicyResource::collection(DiscountPolicy::query()->with('rules')->orderBy('priority')->orderBy('code')->get()), __('sales.messages.discount_policies_list'));
    }

    /**
     * Creates a discount policy.
     */
    #[OA\Post(
        path: '/api/v1/sales/discount-policies',
        operationId: 'createSalesDiscountPolicy',
        description: 'Creates a discount policy or promotion with its rules. Reserved to the sales managers (owner, admin, sales_manager). A percentage policy gives at most 100%; every rule targets a product, a customer or a customer group of the active company.',
        summary: 'Create discount policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SalesCycleDiscountPolicyRequest')),
        tags: ['Catalog', 'Pricing'],
        responses: [
            new OA\Response(response: 201, description: 'Discount policy created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleDiscountPolicy'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function store(StoreDiscountPolicyRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('manage', DiscountPolicy::class);
        $policy = $this->policies->save(new DiscountPolicy(['tenant_id' => $tenantContext->getTenantId(), 'is_active' => true, 'priority' => 100, 'is_stackable' => true, 'auto_apply' => false]), $request->validated());

        return $this->successResponse(new DiscountPolicyResource($policy), __('sales.messages.discount_policy_created'), Response::HTTP_CREATED);
    }

    /**
     * A discount policy with its rules.
     */
    #[OA\Get(
        path: '/api/v1/sales/discount-policies/{id}',
        operationId: 'getSalesDiscountPolicy',
        description: 'Returns a discount policy of the active company with its rules.',
        summary: 'Get discount policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Catalog', 'Pricing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleDiscountPolicyId')],
        responses: [
            new OA\Response(response: 200, description: 'Discount policy', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleDiscountPolicy'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new DiscountPolicyResource(DiscountPolicy::query()->with('rules')->findOrFail($id)), __('sales.messages.discount_policy_retrieved'));
    }

    /**
     * Updates a discount policy.
     */
    #[OA\Put(
        path: '/api/v1/sales/discount-policies/{id}',
        operationId: 'updateSalesDiscountPolicy',
        description: 'Updates a discount policy (sales managers only); omitted fields keep their value and `rules`, when given, replace the rules. Documents already saved keep the prices they got.',
        summary: 'Update discount policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SalesCycleDiscountPolicyRequest')),
        tags: ['Catalog', 'Pricing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleDiscountPolicyId')],
        responses: [
            new OA\Response(response: 200, description: 'Discount policy updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleDiscountPolicy'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function update(StoreDiscountPolicyRequest $request, string $id): JsonResponse
    {
        $this->authorize('manage', DiscountPolicy::class);
        $policy = $this->policies->save(DiscountPolicy::query()->findOrFail($id), $request->validated());

        return $this->successResponse(new DiscountPolicyResource($policy), __('sales.messages.discount_policy_updated'));
    }

    /**
     * Deletes a discount policy.
     */
    #[OA\Delete(
        path: '/api/v1/sales/discount-policies/{id}',
        operationId: 'deleteSalesDiscountPolicy',
        description: 'Deletes a discount policy and its rules (soft delete; sales managers only). Documents already saved keep the prices they got.',
        summary: 'Delete discount policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Catalog', 'Pricing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleDiscountPolicyId')],
        responses: [
            new OA\Response(response: 200, description: 'Discount policy deleted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->authorize('manage', DiscountPolicy::class);
        $this->policies->delete(DiscountPolicy::query()->findOrFail($id));

        return $this->successResponse(null, __('sales.messages.discount_policy_deleted'));
    }
}
