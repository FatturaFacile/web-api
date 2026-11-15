<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Transportation\Http\Controllers\Concerns\InteractsWithTransportation;
use Modules\Transportation\Http\Requests\FreightInvoiceRequest;
use Modules\Transportation\Http\Resources\FreightInvoiceResource;
use Modules\Transportation\Models\TmsFreightInvoice;
use Modules\Transportation\Services\FreightInvoiceService;
use OpenApi\Attributes as OA;

/**
 * Carrier invoices with their audit against the expected rates and the dispute of what was over-billed.
 */
final class FreightInvoiceController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly FreightInvoiceService $invoices) {}

    #[OA\Get(path: '/api/v1/transportation/freight-invoices', operationId: 'listTransportationFreightInvoices', description: 'Carrier invoices with their lines, latest first. Filters: carrier_id, audit_status, dispute_status, from, to (invoice date), search (invoice number).', summary: 'List freight invoices', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(name: 'carrier_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'audit_status', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'dispute_status', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Freight invoices', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TransportationFreightInvoice'))])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TmsFreightInvoice::class);
        $data = $request->validate([
            'carrier_id' => ['nullable', 'uuid'],
            'audit_status' => ['nullable', Rule::in(TmsFreightInvoice::AuditStatuses)],
            'dispute_status' => ['nullable', Rule::in(TmsFreightInvoice::DisputeStatuses)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $term = isset($data['search']) ? '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $data['search']).'%' : null;
        $invoices = TmsFreightInvoice::query()
            ->with(['carrier:id,code,name', 'lines.transportOrder:id,number'])
            ->where('tenant_id', $this->tenantId())
            ->when(isset($data['carrier_id']), static fn ($query) => $query->where('carrier_id', $data['carrier_id']))
            ->when(isset($data['audit_status']), static fn ($query) => $query->where('audit_status', $data['audit_status']))
            ->when(isset($data['dispute_status']), static fn ($query) => $query->where('dispute_status', $data['dispute_status']))
            ->when(isset($data['from']), static fn ($query) => $query->whereDate('invoice_date', '>=', $data['from']))
            ->when(isset($data['to']), static fn ($query) => $query->whereDate('invoice_date', '<=', $data['to']))
            ->when($term !== null, static fn ($query) => $query->where('invoice_number', 'like', $term))
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->paginate($this->perPage());

        return FreightInvoiceResource::collection($invoices);
    }

    #[OA\Post(path: '/api/v1/transportation/freight-invoices', operationId: 'storeTransportationFreightInvoice', description: 'Records an invoice received from a carrier with one line per billed amount, each optionally linked to the transport order it pays (an order of that carrier). The invoice number is unique per carrier. It starts pending: run the audit to compare it with the expected rates.', summary: 'Record a carrier invoice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationFreightInvoiceRequest')), responses: [
        new OA\Response(response: 201, description: 'Invoice recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationFreightInvoice')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function store(FreightInvoiceRequest $request): JsonResponse
    {
        $this->authorize('create', TmsFreightInvoice::class);

        return (new FreightInvoiceResource($this->invoices->create($this->tenantId(), $request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/transportation/freight-invoices/{id}', operationId: 'showTransportationFreightInvoice', description: 'Return an invoice with its lines, the variance found by the audit and its dispute.', summary: 'Show a carrier invoice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationFreightInvoice')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
    ])]
    public function show(string $id): FreightInvoiceResource
    {
        $record = TmsFreightInvoice::query()->with(['carrier:id,code,name', 'lines.transportOrder:id,number'])->findOrFail($id);
        $this->authorize('view', $record);

        return new FreightInvoiceResource($record);
    }

    #[OA\Put(path: '/api/v1/transportation/freight-invoices/{id}', operationId: 'updateTransportationFreightInvoice', description: 'Change an invoice that is not under dispute and has not been resolved; the lines sent replace the existing ones and the audit result is reset.', summary: 'Update a carrier invoice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationFreightInvoiceRequest')), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationFreightInvoice')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function update(FreightInvoiceRequest $request, string $id): FreightInvoiceResource
    {
        $record = TmsFreightInvoice::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new FreightInvoiceResource($this->invoices->update($record, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/transportation/freight-invoices/{id}', operationId: 'deleteTransportationFreightInvoice', description: 'Delete an invoice that has no dispute (soft delete).', summary: 'Delete a carrier invoice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 204, description: 'Invoice deleted'),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = TmsFreightInvoice::query()->findOrFail($id);
        $this->authorize('delete', $record);

        $this->invoices->delete($record);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/transportation/freight-invoices/{id}/audit', operationId: 'auditTransportationFreightInvoice', description: 'Compares every line with the expected rate of its transport order (the expected cost of the order, else the rate recalculated from the tariff tables). The variance is billed minus expected, in amount and percent of the expected; within the tolerance (default 2 percent) the line is matched, above it over billed, below it under billed, and without an order or a rate it is unmatched. The invoice takes the worst status of its lines and the billed amount becomes the actual cost of the orders.', summary: 'Audit a carrier invoice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'tolerance_percent', type: 'string', example: '2', nullable: true)])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationFreightInvoice')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function audit(Request $request, string $id): FreightInvoiceResource
    {
        $record = TmsFreightInvoice::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return new FreightInvoiceResource($this->invoices->audit($record, isset($data['tolerance_percent']) ? (string) $data['tolerance_percent'] : null));
    }

    #[OA\Post(path: '/api/v1/transportation/freight-invoices/{id}/dispute', operationId: 'disputeTransportationFreightInvoice', description: 'Contests an audited invoice that is over billed, under billed or unmatched, with the reason.', summary: 'Open a dispute', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationFreightInvoice')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function dispute(Request $request, string $id): FreightInvoiceResource
    {
        $record = TmsFreightInvoice::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return new FreightInvoiceResource($this->invoices->dispute($record, (string) $data['reason']));
    }

    #[OA\Post(path: '/api/v1/transportation/freight-invoices/{id}/resolve-dispute', operationId: 'resolveDisputeTransportationFreightInvoice', description: 'Closes an open dispute: accepted (the carrier was right), credited (the carrier credits an amount, up to the over-billed amount) or rejected (the dispute is dropped). A note is required.', summary: 'Resolve a dispute', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['resolution', 'note'], properties: [new OA\Property(property: 'resolution', type: 'string', enum: ['accepted', 'credited', 'rejected']), new OA\Property(property: 'note', type: 'string'), new OA\Property(property: 'credited_amount', type: 'string', example: '35.00', nullable: true)])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationFreightInvoice')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function resolveDispute(Request $request, string $id): FreightInvoiceResource
    {
        $record = TmsFreightInvoice::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['accepted', 'credited', 'rejected'])],
            'note' => ['required', 'string', 'max:2000'],
            'credited_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
        ]);

        return new FreightInvoiceResource($this->invoices->resolveDispute($record, $data['resolution'], (string) $data['note'], isset($data['credited_amount']) ? (string) $data['credited_amount'] : null));
    }
}
