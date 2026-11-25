<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\StatutoryFinancials\Application\Commands\PopulateExplanatoryNotesTablesFromSubledgersCommand;
use Modules\StatutoryFinancials\Application\Commands\PopulateExplanatoryNotesTablesFromSubledgersHandler;
use Modules\StatutoryFinancials\Application\Commands\UpdateExplanatoryNotesCommand;
use Modules\StatutoryFinancials\Application\Commands\UpdateExplanatoryNotesHandler;
use Modules\StatutoryFinancials\Http\Controllers\Concerns\InteractsWithStatutoryFinancials;
use Modules\StatutoryFinancials\Http\Requests\PopulateNotesRequest;
use Modules\StatutoryFinancials\Http\Requests\UpdateNotesRequest;
use Modules\StatutoryFinancials\Http\Resources\NotesResource;
use OpenApi\Attributes as OA;

/**
 * The Nota Integrativa (IT) / Anhang (CH) workspace of a dossier.
 */
final class NotesController extends Controller
{
    use ApiResponse;
    use InteractsWithStatutoryFinancials;

    #[OA\Get(path: '/api/v1/statutory-financials/dossiers/{id}/notes', operationId: 'statutoryFinancialsShowNotes', summary: 'Notes workspace of a dossier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Quantitative tables and narrative sections')])]
    public function show(string $id): JsonResponse
    {
        $dossier = $this->dossier($id);
        Gate::authorize('view', $dossier);

        return $this->successResponse($dossier->notes === null ? null : new NotesResource($dossier->notes));
    }

    #[OA\Post(path: '/api/v1/statutory-financials/dossiers/{id}/notes/populate', operationId: 'statutoryFinancialsPopulateNotes', summary: 'Populate the notes tables from the subledgers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Notes populated')])]
    public function populate(string $id, PopulateNotesRequest $request, PopulateExplanatoryNotesTablesFromSubledgersHandler $handler): JsonResponse
    {
        Gate::authorize('update', $this->dossier($id));

        $notes = $handler->handle(new PopulateExplanatoryNotesTablesFromSubledgersCommand($id, (bool) $request->boolean('overwrite_narrative'), $this->userId()));

        return $this->successResponse(new NotesResource($notes), $this->message('notes_populated'));
    }

    #[OA\Put(path: '/api/v1/statutory-financials/dossiers/{id}/notes', operationId: 'statutoryFinancialsUpdateNotes', summary: 'Edit quantitative tables and narrative sections', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Statutory Financials'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Notes saved')])]
    public function update(string $id, UpdateNotesRequest $request, UpdateExplanatoryNotesHandler $handler): JsonResponse
    {
        Gate::authorize('update', $this->dossier($id));

        $notes = $handler->handle(new UpdateExplanatoryNotesCommand($id, $request->input('tables'), $request->input('sections'), $this->userId()));

        return $this->successResponse(new NotesResource($notes), $this->message('saved'));
    }
}
