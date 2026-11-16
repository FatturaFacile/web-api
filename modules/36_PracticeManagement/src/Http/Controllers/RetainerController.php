<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FeeNote;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\PracticeManagement\Http\Requests\PracticeRequest;
use Modules\PracticeManagement\Http\Resources\PracticeResource;
use Modules\PracticeManagement\Models\Retainer;
use Modules\PracticeManagement\Services\RetainerService;
use OpenApi\Attributes as OA;

/**
 * Retainers (advances on account of fees) received from clients and their application to fee notes.
 */
final class RetainerController extends Controller
{
    public function __construct(private readonly RetainerService $retainers) {}

    /**
     * Return the retainers, optionally of one client or one status.
     */
    #[OA\Get(path: '/api/v1/practice/retainers', operationId: 'listPracticeRetainers', description: 'Return the retainers received from clients, latest first, optionally of one client or one status (open, exhausted, void).', summary: 'List retainers', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [
        new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'exhausted', 'void'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Retainers', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function index(PracticeRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Retainer::class);

        return PracticeResource::collection(Retainer::query()
            ->with(['client', 'legalCase'])
            ->when($request->validated('client_id') !== null, fn ($query) => $query->where('client_id', $request->validated('client_id')))
            ->when($request->validated('status') !== null, fn ($query) => $query->where('status', $request->validated('status')))
            ->latest('received_on')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    /**
     * Return, per client, the retainers received, applied and still available.
     */
    #[OA\Get(path: '/api/v1/practice/retainers/balances', operationId: 'practiceRetainerBalances', description: 'Return, per client, the retainers received, applied to fee notes and still available. Void retainers are excluded.', summary: 'Retainer balances', security: [['bearerAuth' => []]], tags: ['Practice Management'], responses: [
        new OA\Response(response: 200, description: 'Balances per client', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
    ])]
    public function balances(): JsonResponse
    {
        $this->authorize('viewAny', Retainer::class);

        return response()->json(['data' => $this->retainers->balances()]);
    }

    /**
     * Register a retainer received from a client.
     */
    #[OA\Post(path: '/api/v1/practice/retainers', operationId: 'storePracticeRetainer', description: 'Register a retainer (advance on account of fees) received from a client, for the whole client or one of its cases. The amount is a decimal string; the bank receipt itself is booked in Treasury.', summary: 'Record retainer', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeRetainerRequest')), tags: ['Practice Management'], responses: [
        new OA\Response(response: 201, description: 'Retainer recorded', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function store(PracticeRequest $request): JsonResponse
    {
        $this->authorize('create', Retainer::class);

        return (new PracticeResource($this->retainers->record($request->validated(), $request->user()?->getKey())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Apply part of a retainer to a fee note of the same client.
     */
    #[OA\Post(path: '/api/v1/practice/retainers/{id}/apply', operationId: 'applyPracticeRetainer', description: 'Apply part of an open retainer to an unpaid fee note of the same client, never beyond what is left of the retainer or what the fee note still asks. The retainer becomes exhausted when fully applied.', summary: 'Apply retainer to fee note', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeRetainerApplyRequest')), tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 201, description: 'Retainer applied', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function apply(PracticeRequest $request, string $id): JsonResponse
    {
        $application = $this->retainers->apply($this->retainer($id, 'update'), (string) $request->validated('fee_note_id'), (string) $request->validated('amount'), $request->user()?->getKey());

        return (new PracticeResource($application))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Void a retainer entered by mistake, while nothing of it has been applied.
     */
    #[OA\Post(path: '/api/v1/practice/retainers/{id}/void', operationId: 'voidPracticeRetainer', description: 'Void a retainer entered by mistake. Refused once any part of it has been applied to a fee note.', summary: 'Void retainer', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Retainer voided', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function void(string $id): PracticeResource
    {
        return new PracticeResource($this->retainers->void($this->retainer($id, 'update')));
    }

    /**
     * Return what a fee note still asks after the retainers applied to it.
     */
    #[OA\Get(path: '/api/v1/practice/fee-notes/{id}/retainers', operationId: 'practiceFeeNoteRetainers', description: 'Return the retainers applied to a fee note and what the fee note still asks after them.', summary: 'Retainers of a fee note', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Fee note retainers', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
    ])]
    public function feeNote(string $id): JsonResponse
    {
        $this->authorize('viewAny', Retainer::class);

        return response()->json(['data' => $this->retainers->feeNoteSummary(FeeNote::query()->findOrFail($id))]);
    }

    private function retainer(string $id, string $ability): Retainer
    {
        $retainer = Retainer::query()->findOrFail($id);
        $this->authorize($ability, $retainer);

        return $retainer;
    }
}
