<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Inventory\Models\ProductChargeAssignment;
use Modules\Inventory\Models\ProductChargeRate;
use Modules\Inventory\Models\ProductChargeType;
use Modules\Inventory\Services\ProductChargeCalculator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Product Charges', description: 'Supplementary charges per product, billed on a separate line with their own ledger account')]
final class ProductChargeController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    #[OA\Get(
        path: '/api/v1/invoicing/product-charges',
        summary: 'List product charges',
        tags: ['Product Charges'],
        responses: [
            new OA\Response(response: 200, description: 'Charges with their rates', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductChargeType'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List product charges', tags: ['Invoicing'])]
    public function index(): JsonResponse
    {
        return response()->json(['data' => ProductChargeType::query()->with('rates')->orderBy('code')->get()]);
    }

    #[OA\Post(
        path: '/api/v1/invoicing/product-charges',
        summary: 'Create a product charge',
        description: 'A kind of charge and the ledger account it is credited to. What it is called and what it costs are the tenant\'s data.',
        tags: ['Product Charges'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code', 'name', 'calculation_basis', 'revenue_account_id'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'TRA'),
                new OA\Property(property: 'name', type: 'string', example: 'Recycling fee'),
                new OA\Property(property: 'calculation_basis', type: 'string', enum: ProductChargeType::BASES),
                new OA\Property(property: 'revenue_account_id', type: 'string', format: 'uuid'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Charge created', content: new OA\JsonContent(ref: '#/components/schemas/ProductChargeType')),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a product charge', tags: ['Invoicing'])]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('product_charge_types', 'code')->where('tenant_id', $this->tenantContext->getTenantId())],
            'name' => ['required', 'string', 'max:150'],
            'calculation_basis' => ['required', Rule::in(ProductChargeType::BASES)],
            'revenue_account_id' => ['required', 'uuid', $this->tenantExists('chart_of_accounts')],
        ]);

        return response()->json(ProductChargeType::create($validated), 201);
    }

    #[OA\Post(
        path: '/api/v1/invoicing/product-charges/{id}/rates',
        summary: 'Add a rate to a charge',
        description: 'A rate with its validity. Periods of one charge and currency may not overlap.',
        tags: ['Product Charges'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['rate', 'currency', 'valid_from'],
            properties: [
                new OA\Property(property: 'rate', type: 'number', description: 'Per unit, kg or litre, or a percentage of the net line'),
                new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
                new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
                new OA\Property(property: 'valid_to', type: 'string', format: 'date'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Rate added', content: new OA\JsonContent(ref: '#/components/schemas/ProductChargeRate')),
            new OA\Response(response: 404, description: 'Charge not found'),
            new OA\Response(response: 422, description: 'Validation failed or overlapping period'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Add a rate to a charge', tags: ['Invoicing'])]
    public function storeRate(string $id, Request $request): JsonResponse
    {
        $type = ProductChargeType::findOrFail($id);
        $validated = $request->validate([
            'rate' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        $overlaps = ProductChargeRate::query()
            ->where('product_charge_type_id', $type->id)
            ->where('currency', $validated['currency'])
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $validated['valid_from']))
            ->when(! empty($validated['valid_to']), static fn ($query) => $query->whereDate('valid_from', '<=', $validated['valid_to']))
            ->exists();

        if ($overlaps) {
            return response()->json(['message' => 'The period overlaps another rate of this charge.'], 422);
        }

        return response()->json(ProductChargeRate::create($validated + ['product_charge_type_id' => $type->id]), 201);
    }

    #[OA\Post(
        path: '/api/v1/invoicing/product-charges/{id}/products',
        summary: 'Assign a charge to a product',
        tags: ['Product Charges'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['product_id'],
            properties: [
                new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity_per_unit', type: 'number', description: 'What one unit counts for on the basis of the charge: its kg or litres', example: 2.5),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Charge assigned', content: new OA\JsonContent(ref: '#/components/schemas/ProductChargeAssignment')),
            new OA\Response(response: 404, description: 'Charge not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Assign a charge to a product', tags: ['Invoicing'])]
    public function assign(string $id, Request $request): JsonResponse
    {
        $type = ProductChargeType::findOrFail($id);
        $validated = $request->validate([
            'product_id' => ['required', 'uuid', $this->tenantExists('products')],
            'quantity_per_unit' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $assignment = ProductChargeAssignment::query()->updateOrCreate(
            ['product_charge_type_id' => $type->id, 'product_id' => $validated['product_id']],
            ['quantity_per_unit' => $validated['quantity_per_unit'] ?? 1]
        );

        return response()->json($assignment, 201);
    }

    #[OA\Post(
        path: '/api/v1/invoicing/product-charges/preview',
        summary: 'Preview the charges of a document',
        description: 'The separate lines the charges add to the given lines on that date. Invoices add them by themselves; quotes and orders can show them from here.',
        tags: ['Product Charges'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['date', 'currency', 'lines'],
            properties: [
                new OA\Property(property: 'date', type: 'string', format: 'date'),
                new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'quantity', type: 'number'),
                    new OA\Property(property: 'unit_price', type: 'number'),
                    new OA\Property(property: 'tax_rate', type: 'number'),
                    new OA\Property(property: 'discount_percentage', type: 'number'),
                ])),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Charge lines', content: new OA\JsonContent(properties: [new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductChargeLine'))])),
            new OA\Response(response: 422, description: 'Validation failed or a rate is missing'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Preview the charges of a document', tags: ['Invoicing'])]
    public function preview(Request $request, ProductChargeCalculator $calculator): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.unit_price' => ['required', 'numeric'],
            'lines.*.tax_rate' => ['required', 'numeric'],
            'lines.*.discount_percentage' => ['nullable', 'numeric'],
        ]);

        return response()->json(['lines' => $calculator->linesFor($validated['lines'], $validated['date'], $validated['currency'], (string) $this->tenantContext->getTenantId())]);
    }

    private function tenantExists(string $table): Exists
    {
        $tenantId = $this->tenantContext->getTenantId();

        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}
