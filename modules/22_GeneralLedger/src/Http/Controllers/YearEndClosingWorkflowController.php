<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\GeneralLedger\Http\Requests\ClosingWorkflowRequest;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Services\YearEndClosingWorkflowService;
use OpenApi\Attributes as OA;

/**
 * The 4-stage year-end closing and opening workflow of a fiscal year, per ledger book.
 */
final class YearEndClosingWorkflowController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly YearEndClosingWorkflowService $workflow) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/fiscal-years/{id}/closing-workflow',
        operationId: 'getGeneralLedgerYearEndClosingWorkflow',
        description: 'Status of the 4-stage year-end workflow of a fiscal year in a ledger book (`ledger` 01 statutory, 02 tax, 03 ifrs; default 01): period lock, net result, closing and opening, each completed, ready or blocked, with its summary figures, journal entry and who ran it.',
        summary: 'Year-end closing workflow status',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Years'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'ledger', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['01', '02', '03'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Workflow status', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, description: 'Fiscal year not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(ClosingWorkflowRequest $request, string $id): JsonResponse
    {
        $year = FiscalYear::findOrFail($id);
        $this->authorize('view', $year);

        return $this->successResponse($this->workflow->status($year, $request->book()));
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-years/{id}/closing-workflow/stages/{stage}',
        operationId: 'runGeneralLedgerYearEndClosingStage',
        description: 'Runs one stage (1 period lock, 2 net result, 3 closing, 4 opening) of the year-end workflow in a ledger book. Stages run in order (409 otherwise); a stage already completed is not run again and its recorded result is returned with `already_completed` true, so the call is safe to repeat.',
        summary: 'Run a year-end closing stage',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Years'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'stage', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 4)),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'ledger', type: 'string', enum: ['01', '02', '03'])])),
        responses: [
            new OA\Response(response: 200, description: 'Stage result', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 409, description: 'An earlier stage is not completed or a period cannot be closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function run(ClosingWorkflowRequest $request, string $id, int $stage): JsonResponse
    {
        abort_unless($stage >= 1 && $stage <= 4, 404);

        $year = FiscalYear::findOrFail($id);
        $this->authorize('update', $year);

        return $this->successResponse($this->workflow->execute((string) $year->id, $request->book(), $stage, (string) $request->user()?->id));
    }
}
