<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BillableActivity;
use App\Models\FeeNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\PracticeManagement\Http\Requests\PracticeRequest;
use Modules\PracticeManagement\Http\Resources\PracticeResource;
use Modules\PracticeManagement\Models\PeriodClosing;
use Modules\PracticeManagement\Services\PracticeService;
use OpenApi\Attributes as OA;

/**
 * Trust offices and law firms: activity control, fee notes from activities and the multi-client period closing.
 */
final class PracticeController extends Controller
{
    public function __construct(private readonly PracticeService $practice) {}

    #[OA\Get(path: '/api/v1/practice/activity-control', operationId: 'practiceActivityControl', description: 'Return the logged time of a period by professional and case, billed and not, and how many unbilled activities are older than the threshold.', summary: 'Activity control', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'stale_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30)),
    ], responses: [
        new OA\Response(response: 200, description: 'Activity control', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function activityControl(PracticeRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PeriodClosing::class);

        return response()->json(['data' => $this->practice->activityControl((string) $request->validated('from'), (string) $request->validated('to'), $request->validated('user_id'), (int) ($request->validated('stale_days') ?? 30))]);
    }

    #[OA\Post(path: '/api/v1/practice/fee-notes/from-activities', operationId: 'practiceFeeNoteFromActivities', description: 'Create a draft fee note from unbilled activities of one client and mark them billed. Contribution, withholding and VAT rates are entered.', summary: 'Fee note from activities', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeFeeNoteRequest')), tags: ['Practice Management'], responses: [
        new OA\Response(response: 201, description: 'Fee note created', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function feeNoteFromActivities(PracticeRequest $request): JsonResponse
    {
        $this->authorize('create', PeriodClosing::class);

        return (new PracticeResource($this->practice->feeNoteFromActivities($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/practice/fee-notes/{id}/activities', operationId: 'practiceFeeNoteActivities', description: 'Return the activities billed by a fee note.', summary: 'Activities of a fee note', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Activities', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
    ])]
    public function feeNoteActivities(string $id): JsonResponse
    {
        $this->authorize('viewAny', PeriodClosing::class);
        $note = FeeNote::query()->findOrFail($id);

        return response()->json(['data' => BillableActivity::query()->where('fee_note_id', $note->id)->orderBy('date')->get()]);
    }

    #[OA\Get(path: '/api/v1/practice/closing-overview', operationId: 'practiceClosingOverview', description: 'Return, for every client with work, fee notes or a closing in the period, what is left to bill, to issue and to collect, and the state of the closing.', summary: 'Multi-client closing view', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Overview', content: new OA\JsonContent(ref: '#/components/schemas/PracticeDataResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function closingOverview(PracticeRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PeriodClosing::class);

        return response()->json(['data' => $this->practice->closingOverview((string) $request->validated('from'), (string) $request->validated('to'))]);
    }

    #[OA\Post(path: '/api/v1/practice/closings', operationId: 'practiceOpenClosing', description: 'Open the closing of a period for a client, with its checklist.', summary: 'Open closing', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeClosingRequest')), tags: ['Practice Management'], responses: [
        new OA\Response(response: 201, description: 'Closing opened', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function openClosing(PracticeRequest $request): JsonResponse
    {
        $this->authorize('create', PeriodClosing::class);

        return (new PracticeResource($this->practice->openClosing($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/practice/closings/{id}/checklist', operationId: 'practiceClosingChecklist', description: 'Tick the checks of a closing.', summary: 'Update closing checklist', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PracticeChecklistRequest')), tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Checklist updated', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function checklist(PracticeRequest $request, string $id): PracticeResource
    {
        $closing = $this->closing($id, 'update');

        return new PracticeResource($this->practice->updateChecklist($closing, $request->validated('items'), $request->validated('notes')));
    }

    #[OA\Post(path: '/api/v1/practice/closings/{id}/close', operationId: 'practiceCloseClosing', description: 'Close the period of a client: every check done and nothing unbilled, unless allowed with a reason.', summary: 'Close period', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/PracticeCloseRequest')), tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Period closed', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function close(PracticeRequest $request, string $id): PracticeResource
    {
        $closing = $this->closing($id, 'update');

        return new PracticeResource($this->practice->close($closing, $request->user()?->getKey(), (bool) $request->validated('allow_unbilled', false), $request->validated('reason')));
    }

    #[OA\Post(path: '/api/v1/practice/closings/{id}/reopen', operationId: 'practiceReopenClosing', description: 'Reopen a closed period. Owners and administrators only.', summary: 'Reopen period', security: [['bearerAuth' => []]], tags: ['Practice Management'], parameters: [new OA\Parameter(ref: '#/components/parameters/PracticeId')], responses: [
        new OA\Response(response: 200, description: 'Period reopened', content: new OA\JsonContent(ref: '#/components/schemas/PracticeRecordResponse')),
        new OA\Response(ref: '#/components/responses/PracticeUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PracticeForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PracticeNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PracticeUnprocessable', response: 422),
    ])]
    public function reopen(string $id): PracticeResource
    {
        return new PracticeResource($this->practice->reopen($this->closing($id, 'reopen')));
    }

    private function closing(string $id, string $ability): PeriodClosing
    {
        $closing = PeriodClosing::query()->findOrFail($id);
        $this->authorize($ability, $closing);

        return $closing;
    }
}
