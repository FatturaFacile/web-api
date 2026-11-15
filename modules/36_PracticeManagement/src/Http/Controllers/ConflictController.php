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
use Modules\PracticeManagement\Models\PracticeCaseParty;
use Modules\PracticeManagement\Models\PracticeConflictCheck;
use Modules\PracticeManagement\Services\ConflictCheckService;
use OpenApi\Attributes as OA;

/**
 * Conflicts-of-interest search and the parties recorded on matters that feed it.
 */
final class ConflictController extends Controller
{
    public function __construct(private readonly ConflictCheckService $conflicts) {}

    #[OA\Post(path: '/api/v1/practice/conflict-checks', operationId: 'runPracticeConflictCheck', description: 'Search a name among the parties and the clients of all matters and keep the outcome (clear, review or conflict) with its matches as evidence.', summary: 'Run conflicts check', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeConflictCheckRequest')), tags: ['Practice Management'], responses: [
        new OA\Response(response: 201, description: 'Check outcome', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function check(PracticeRequest $request): JsonResponse
    {
        $this->authorize('create', PracticeConflictCheck::class);
        $check = $this->conflicts->check($request->validated('name'), $request->validated('role'), $request->validated('contact_id'), $request->user()?->getKey());

        return (new PracticeResource($check))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/practice/conflict-checks', operationId: 'listPracticeConflictChecks', description: 'Return the conflicts checks run so far, latest first.', summary: 'List conflicts checks', security: [['bearerAuth' => []]], tags: ['Practice Management'], responses: [
        new OA\Response(response: 200, description: 'Checks', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
    ])]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PracticeConflictCheck::class);

        return PracticeResource::collection(PracticeConflictCheck::query()->latest('created_at')->latest('id')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    #[OA\Get(path: '/api/v1/practice/case-parties', operationId: 'listPracticeCaseParties', description: 'Return the parties recorded on matters, optionally of one matter.', summary: 'List matter parties', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [
        new OA\Parameter(name: 'legal_case_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Parties', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function parties(PracticeRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PracticeCaseParty::class);

        return PracticeResource::collection(PracticeCaseParty::query()->with('legalCase')
            ->when($request->validated('legal_case_id') !== null, fn ($query) => $query->where('legal_case_id', $request->validated('legal_case_id')))
            ->orderBy('name')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    #[OA\Post(path: '/api/v1/practice/case-parties', operationId: 'storePracticeCaseParty', description: 'Record a party (client, opposing party, related party or witness) on a matter so it is found by later conflicts checks.', summary: 'Record matter party', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeCasePartyRequest')), tags: ['Practice Management'], responses: [
        new OA\Response(response: 201, description: 'Party recorded', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function storeParty(PracticeRequest $request): JsonResponse
    {
        $this->authorize('create', PracticeCaseParty::class);
        $party = new PracticeCaseParty;
        $party->fill($request->validated());
        $party->save();

        return (new PracticeResource($party))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/practice/case-parties/{id}', operationId: 'deletePracticeCaseParty', description: 'Remove a party from a matter.', summary: 'Delete matter party', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 204, description: 'Party deleted'),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
    ])]
    public function destroyParty(string $id): Response
    {
        $party = PracticeCaseParty::query()->findOrFail($id);
        $this->authorize('update', $party);
        $party->delete();

        return response()->noContent();
    }
}
