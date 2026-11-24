<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\PaymentScheduleRequest;
use App\Http\Requests\Catalog\StorePaymentTermRequest;
use App\Http\Resources\CatalogResource;
use App\Models\PaymentTerm;
use App\Services\Catalog\PaymentTermScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class PaymentTermController extends Controller
{
    use HandlesLookupCrud {
        index as lookupIndex;
        show as lookupShow;
        destroy as lookupDestroy;
    }

    public function __construct(private readonly PaymentTermScheduler $scheduler) {}

    protected function modelClass(): string
    {
        return PaymentTerm::class;
    }

    protected function label(): string
    {
        return __('api.labels.payment_term');
    }

    #[OA\Get(
        path: '/api/v1/catalog/payment-terms',
        operationId: 'getPaymentTerms',
        description: 'List payment terms and installment definitions (e.g. 30/60/90 days, RIBA, Rimessa Diretta).',
        summary: 'List payment terms',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Payment Terms'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment terms retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->lookupIndex($request);
    }

    #[OA\Get(
        path: '/api/v1/catalog/payment-terms/{id}',
        operationId: 'getPaymentTerm',
        description: 'Get details of a specific payment term.',
        summary: 'Get payment term',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Payment Terms'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment term retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Payment term not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->lookupShow($id);
    }

    #[OA\Post(
        path: '/api/v1/catalog/payment-terms',
        operationId: 'storePaymentTerm',
        description: 'Create a new payment term definition with installment percentages and delay intervals.',
        summary: 'Create payment term',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Payment Terms'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: '30DF'),
                    new OA\Property(property: 'name', type: 'string', example: '30 Days End of Month'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Payment term created successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StorePaymentTermRequest $request): JsonResponse
    {
        $term = $this->persist(new PaymentTerm, $request->validated());

        return $this->successResponse(new CatalogResource($term), __('catalog.messages.payment_term_created'), Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/catalog/payment-terms/{id}',
        operationId: 'updatePaymentTerm',
        description: 'Update payment term configuration.',
        summary: 'Update payment term',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Payment Terms'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Payment term updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Payment term not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StorePaymentTermRequest $request, string $id): JsonResponse
    {
        $term = $this->persist(PaymentTerm::findOrFail($id), $request->validated());

        return $this->successResponse(new CatalogResource($term), __('catalog.messages.payment_term_updated'));
    }

    #[OA\Delete(
        path: '/api/v1/catalog/payment-terms/{id}',
        operationId: 'deletePaymentTerm',
        description: 'Delete a payment term.',
        summary: 'Delete payment term',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Payment Terms'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment term deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Payment term not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        return $this->lookupDestroy($id);
    }

    /**
     * Compute the due-date schedule (installments and early-payment discount) for an amount.
     */
    #[OA\Get(
        path: '/api/v1/catalog/payment-terms/{id}/schedule',
        operationId: 'computePaymentSchedule',
        description: 'Compute the installment payment schedule and early payment discount dates for a given total amount.',
        summary: 'Compute payment schedule',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog', 'Payment Terms'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'amount', in: 'query', required: true, schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Schedule computed successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Payment term not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function schedule(PaymentScheduleRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();

        $schedule = $this->scheduler->schedule(
            PaymentTerm::findOrFail($id),
            (float) $data['amount'],
            isset($data['date']) ? Carbon::parse($data['date']) : now(),
        );

        return $this->successResponse($schedule, __('catalog.messages.schedule_computed'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(PaymentTerm $term, array $data): PaymentTerm
    {
        return PaymentTerm::resolveConnection()->transaction(function () use ($term, $data): PaymentTerm {
            $installments = $data['installments'] ?? null;
            unset($data['installments']);

            $term->fill($data)->save();

            if ($installments !== null) {
                $term->installments()->delete();
                foreach (array_values($installments) as $index => $row) {
                    $term->installments()->create([
                        'sequence' => $index + 1,
                        'percent' => $row['percent'],
                        'days' => $row['days'],
                    ]);
                }
            }

            return $term->load('installments');
        });
    }
}
