<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ReviseQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Http\Responses\ApiResponse;
use App\Models\Quote;
use App\Services\Sales\QuoteLifecycleService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Versions and validity of the quotes: revising an open quote and expiring the quotes past their validity date.
 */
class QuoteRevisionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly QuoteLifecycleService $lifecycle) {}

    /**
     * Creates the next revision of an open quote.
     */
    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/revise',
        operationId: 'reviseSalesQuote',
        description: 'Creates the next revision of an open quote (draft, pending approval, approved or sent): a new draft quote with its own number on the quote series, revision_number + 1 and revision_of_quote_id pointing at the revised quote, with the lines given or those of the revised quote. The revised quote becomes superseded (final: it can no longer be accepted, converted or invoiced).',
        summary: 'Revise quote',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SalesCycleReviseQuoteRequest')),
        tags: ['Quotes'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 201, description: 'Revision created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'object', description: 'The new quote with its lines'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function revise(ReviseQuoteRequest $request, string $id): JsonResponse
    {
        $revision = $this->lifecycle->revise(Quote::query()->findOrFail($id), $request->validated());

        return $this->successResponse(new QuoteResource($revision), __('sales.messages.quote_revised'), Response::HTTP_CREATED);
    }

    /**
     * Expires the open quotes of the company past their validity date.
     */
    #[OA\Post(
        path: '/api/v1/sales/quotes/expire',
        operationId: 'expireSalesQuotes',
        description: 'Moves every open quote (draft, approved or sent) of the active company whose valid_until is before today, on the calendar of the company, to expired. The same run happens every night for every company (sales:expire-quotes).',
        summary: 'Expire quotes past validity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 200, description: 'Quotes expired', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', properties: [new OA\Property(property: 'expired', type: 'integer', example: 3)], type: 'object'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
        ]
    )]
    public function expire(TenantContext $tenantContext): JsonResponse
    {
        return $this->successResponse(['expired' => $this->lifecycle->expireDue((string) $tenantContext->getTenantId())], __('sales.messages.quotes_expired'));
    }
}
