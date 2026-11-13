<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Http\Requests\StorePaymentProposalRequest;
use Modules\Treasury\Http\Resources\PaymentProposalResource;
use Modules\Treasury\Models\PaymentProposal;
use Modules\Treasury\Services\PaymentProposalService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Supplier payment proposals: selection by due date and early-payment discount, approval by someone other than the
 * author, conversion into an approved payment batch exported as pain.001 by the payment batch routes.
 */
final class PaymentProposalController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentProposalService $proposals) {}

    /**
     * The proposals of the tenant, newest first.
     */
    #[OA\Get(
        path: '/api/v1/treasury/payment-proposals',
        operationId: 'listTreasuryPaymentProposals',
        description: 'Lists the supplier payment proposals of the active tenant, newest first, optionally by status, paginated; sortable and filterable by column (grid contract).',
        summary: 'List payment proposals',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Proposals'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'converted', 'rejected'])), new OA\Parameter(ref: '#/components/parameters/TreasuryPerPage')],
        responses: [
            new OA\Response(response: 200, description: 'Proposals', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryPaymentProposalResource')),
                new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer')]),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:draft,converted,rejected']])['status'] ?? null;
        $page = PaymentProposal::query()->when($status, fn ($query, string $value) => $query->where('status', $value))->latest()->gridPaginate(RequestedPerPage::resolve(25));

        return $this->successResponse(PaymentProposalResource::collection($page->items()), 'Payment proposals', meta: ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    /**
     * Prepares a draft proposal.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-proposals',
        operationId: 'createTreasuryPaymentProposal',
        description: 'Selects the open supplier invoices in the currency of the bank account, approved for payment and not already in another draft proposal or pending payment batch: those due by due_until, and (include_discounts) those whose early-payment discount of the supplier payment term is still available on the execution date, proposed net of the discount. Invoices of suppliers without an IBAN are returned in meta.excluded.',
        summary: 'Prepare payment proposal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStorePaymentProposalRequest')),
        tags: ['Treasury Payment Proposals'],
        responses: [
            new OA\Response(response: 201, description: 'Proposal prepared', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryPaymentProposalResource'),
                new OA\Property(property: 'meta', properties: [new OA\Property(property: 'excluded', type: 'array', items: new OA\Items(type: 'object'))], type: 'object'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function store(StorePaymentProposalRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $result = $this->proposals->propose((string) $tenantContext->getTenantId(), $request->validated(), (string) $request->user()?->getAuthIdentifier());

        return $this->successResponse(new PaymentProposalResource($result['proposal']), 'Payment proposal prepared', Response::HTTP_CREATED, ['excluded' => $result['excluded']]);
    }

    /**
     * One proposal with its lines.
     */
    #[OA\Get(
        path: '/api/v1/treasury/payment-proposals/{id}',
        operationId: 'getTreasuryPaymentProposal',
        description: 'Returns a payment proposal with its lines.',
        summary: 'Get payment proposal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Proposals'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryProposalId')],
        responses: [
            new OA\Response(response: 200, description: 'Proposal', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryPaymentProposalResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new PaymentProposalResource(PaymentProposal::query()->with('lines')->findOrFail($id)), 'Payment proposal');
    }

    /**
     * Approves a proposal and turns it into an approved payment batch.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-proposals/{id}/approve',
        operationId: 'approveTreasuryPaymentProposal',
        description: 'Approves a draft proposal (segregation of duties: never by its author) and creates the payment batch, created by the author and approved by the approver, ready for POST /api/v1/treasury/payment-batches/{id}/export (pain.001, SEPA or Swiss). Refused when an invoice changed since the proposal.',
        summary: 'Approve payment proposal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Proposals'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryProposalId')],
        responses: [
            new OA\Response(response: 200, description: 'Proposal approved and converted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryPaymentProposalResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function approve(Request $request, string $id): JsonResponse
    {
        $proposal = PaymentProposal::query()->findOrFail($id);
        $approved = $this->proposals->approve((string) $proposal->id, (string) $request->user()?->getAuthIdentifier(), $request->ip());

        return $this->successResponse(new PaymentProposalResource($approved), 'Payment proposal approved');
    }

    /**
     * Rejects a draft proposal.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-proposals/{id}/reject',
        operationId: 'rejectTreasuryPaymentProposal',
        description: 'Rejects a draft proposal with a reason; its invoices can enter a new proposal.',
        summary: 'Reject payment proposal',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 1000)])),
        tags: ['Treasury Payment Proposals'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryProposalId')],
        responses: [
            new OA\Response(response: 200, description: 'Proposal rejected', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryPaymentProposalResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function reject(Request $request, string $id): JsonResponse
    {
        $proposal = PaymentProposal::query()->findOrFail($id);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->successResponse(new PaymentProposalResource($this->proposals->reject((string) $proposal->id, $reason)), 'Payment proposal rejected');
    }
}
