<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Transportation\Http\Controllers\Concerns\InteractsWithTransportation;
use Modules\Transportation\Http\Resources\QuoteResource;
use Modules\Transportation\Models\TmsQuote;
use Modules\Transportation\Services\QuoteService;
use OpenApi\Attributes as OA;

/**
 * Freight quotes of the active company.
 */
final class QuoteController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly QuoteService $service) {}

    #[OA\Get(path: '/api/v1/transportation/quotes', operationId: 'listTransportationQuotes', description: 'Freight quotes of the active company. Filters: status, transport_order_id, from, to.', summary: 'List freight quotes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['open', 'awarded', 'cancelled'])), new OA\Parameter(name: 'transport_order_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'from', description: 'Y-m-d', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', description: 'Y-m-d', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))], responses: [
        new OA\Response(response: 200, description: 'Freight quotes', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TransportationQuote'))])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TmsQuote::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(TmsQuote::Statuses)], 'transport_order_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return QuoteResource::collection(
            TmsQuote::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['transport_order_id']), static fn ($query) => $query->where('transport_order_id', $filters['transport_order_id']))
                ->when(isset($filters['from']), static fn ($query) => $query->whereDate('ship_date', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->whereDate('ship_date', '<=', $filters['to']))
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Get(path: '/api/v1/transportation/quotes/{id}', operationId: 'showTransportationQuote', description: 'Return a freight quote.', summary: 'Show a freight quote', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Freight quote', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationQuote')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
    ])]
    public function show(string $id): QuoteResource
    {
        $record = TmsQuote::query()->findOrFail($id);
        $this->authorize('view', $record);

        return new QuoteResource($record);
    }

    #[OA\Delete(path: '/api/v1/transportation/quotes/{id}', operationId: 'deleteTransportationQuote', description: 'Delete a quote that is not awarded (soft delete).', summary: 'Delete a freight quote', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 204, description: 'Freight quote deleted'),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = TmsQuote::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
