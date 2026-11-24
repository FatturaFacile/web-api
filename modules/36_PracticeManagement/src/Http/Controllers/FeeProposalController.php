<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\PracticeManagement\Http\Requests\PracticeRequest;
use Modules\PracticeManagement\Http\Resources\PracticeResource;
use Modules\PracticeManagement\Models\PracticeFeeProposal;
use Modules\PracticeManagement\Services\FeeProposalService;
use OpenApi\Attributes as OA;

/**
 * Fee proposals to prospective clients; the accepted ones open the matter.
 */
final class FeeProposalController extends Controller
{
    public function __construct(private readonly FeeProposalService $proposals) {}

    #[OA\Get(path: '/api/v1/practice/fee-proposals', operationId: 'listPracticeFeeProposals', description: 'Return the fee proposals, latest first, optionally of one client or one status.', summary: 'List fee proposals', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [
        new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'sent', 'accepted', 'declined', 'expired'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Proposals', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function index(PracticeRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PracticeFeeProposal::class);

        return PracticeResource::collection(PracticeFeeProposal::query()->with(['client', 'legalCase'])
            ->when($request->validated('client_id') !== null, fn ($query) => $query->where('client_id', $request->validated('client_id')))
            ->when($request->validated('status') !== null, fn ($query) => $query->where('status', $request->validated('status')))
            ->orderByDesc('number')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    #[OA\Post(path: '/api/v1/practice/fee-proposals', operationId: 'storePracticeFeeProposal', description: 'Draft a fee proposal, hourly (rate and estimated hours) or fixed. The estimated total is computed by the server.', summary: 'Draft fee proposal', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeFeeProposalRequest')), tags: ['Practice Management'], responses: [
        new OA\Response(response: 201, description: 'Proposal drafted', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function store(PracticeRequest $request): JsonResponse
    {
        $this->authorize('create', PracticeFeeProposal::class);

        return (new PracticeResource($this->proposals->create($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/practice/fee-proposals/{id}', operationId: 'showPracticeFeeProposal', description: 'Return one fee proposal.', summary: 'Show fee proposal', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Proposal', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
    ])]
    public function show(string $id): PracticeResource
    {
        $proposal = PracticeFeeProposal::query()->with(['client', 'legalCase'])->findOrFail($id);
        $this->authorize('view', $proposal);

        return new PracticeResource($proposal);
    }

    #[OA\Put(path: '/api/v1/practice/fee-proposals/{id}', operationId: 'updatePracticeFeeProposal', description: 'Change a draft proposal; the estimated total is recomputed.', summary: 'Update fee proposal', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeFeeProposalRequest')), tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Proposal updated', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function update(PracticeRequest $request, string $id): PracticeResource
    {
        $proposal = PracticeFeeProposal::query()->findOrFail($id);
        $this->authorize('update', $proposal);

        return new PracticeResource($this->proposals->update($proposal, $request->validated()));
    }

    #[OA\Get(path: '/api/v1/practice/fee-proposals/{id}/pdf', operationId: 'pdfPracticeFeeProposal', description: 'The fee proposal as a PDF document: client, scope, fee basis, estimated total and validity.', summary: 'Fee proposal PDF', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'PDF document', content: new OA\MediaType(mediaType: 'application/pdf')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
    ])]
    public function pdf(string $id): Response
    {
        $proposal = PracticeFeeProposal::query()->findOrFail($id);
        $this->authorize('view', $proposal);

        return response($this->proposals->pdf($proposal), Response::HTTP_OK, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$proposal->number.'.pdf"']);
    }

    #[OA\Post(path: '/api/v1/practice/fee-proposals/expire', operationId: 'expirePracticeFeeProposals', description: 'Mark as expired every sent proposal whose validity date has passed. The same runs every day on its own.', summary: 'Expire overdue fee proposals', security: [['bearerAuth' => []]], tags: ['Practice Management'], responses: [
        new OA\Response(response: 200, description: 'Number of proposals expired', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'expired', type: 'integer')], type: 'object')])),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
    ])]
    public function expire(): JsonResponse
    {
        $this->authorize('create', PracticeFeeProposal::class);

        return response()->json(['data' => ['expired' => $this->proposals->expireOverdue()]]);
    }

    #[OA\Post(path: '/api/v1/practice/fee-proposals/{id}/send', operationId: 'sendPracticeFeeProposal', description: 'Mark a draft proposal as sent to the client.', summary: 'Send fee proposal', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Proposal sent', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function send(string $id): PracticeResource
    {
        $proposal = PracticeFeeProposal::query()->findOrFail($id);
        $this->authorize('update', $proposal);

        return new PracticeResource($this->proposals->send($proposal));
    }

    #[OA\Post(path: '/api/v1/practice/fee-proposals/{id}/accept', operationId: 'acceptPracticeFeeProposal', description: 'Accept a sent proposal and open the matter for the client. Needs a conflicts check of the client; a check with result conflict is accepted only with an override reason.', summary: 'Accept fee proposal', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeFeeProposalAcceptRequest')), tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Proposal accepted, matter opened', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function accept(PracticeRequest $request, string $id): PracticeResource
    {
        $proposal = PracticeFeeProposal::query()->findOrFail($id);
        $this->authorize('update', $proposal);

        return new PracticeResource($this->proposals->accept($proposal, $request->validated('conflict_check_id'), $request->validated('override_reason'))->load(['client', 'legalCase']));
    }

    #[OA\Post(path: '/api/v1/practice/fee-proposals/{id}/decline', operationId: 'declinePracticeFeeProposal', description: 'Record that the client declined a sent proposal.', summary: 'Decline fee proposal', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'notes', type: 'string', nullable: true)])), tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Proposal declined', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function decline(PracticeRequest $request, string $id): PracticeResource
    {
        $proposal = PracticeFeeProposal::query()->findOrFail($id);
        $this->authorize('update', $proposal);

        return new PracticeResource($this->proposals->decline($proposal, $request->validated('notes')));
    }
}
