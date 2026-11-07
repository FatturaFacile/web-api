<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Intrastat\Http\Requests\DeclarationLineRequest;
use Modules\Intrastat\Http\Resources\DeclarationLineResource;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Models\IntrastatDeclarationLine;
use OpenApi\Attributes as OA;

/**
 * The detail rows of a list that has not been submitted. Rows are numbered progressively within their section; deleting
 * a row renumbers the following ones. Any change sends a validated list back to draft.
 */
final class DeclarationLineController extends Controller
{
    use ApiResponse;

    #[OA\Post(path: '/api/v1/intrastat/declarations/{id}/lines', operationId: 'storeIntrastatDeclarationLine', description: 'Add a row to a section of the list. Only the shape and the code lists are checked here; the mandatory columns are checked by the validation of the list.', summary: 'Add INTRA row', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IntrastatLineRequest')), tags: ['Intrastat'], parameters: [new OA\Parameter(ref: '#/components/parameters/IntrastatId')], responses: [
        new OA\Response(response: 201, description: 'Row added', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatDeclarationLineResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/IntrastatUnprocessable', response: 422),
    ])]
    public function store(DeclarationLineRequest $request, string $id): JsonResponse
    {
        $declaration = $this->editableDeclaration($id);

        if ($declaration instanceof JsonResponse) {
            return $declaration;
        }

        $line = DB::transaction(function () use ($declaration, $request): IntrastatDeclarationLine {
            $section = (int) $request->validated('section');
            IntrastatDeclaration::query()->whereKey($declaration->id)->lockForUpdate()->first();
            $next = ((int) $declaration->lines()->where('section', $section)->max('line_number')) + 1;
            $line = $declaration->lines()->create([...$request->validated(), 'line_number' => $next]);
            $declaration->markEdited();

            return $line;
        });

        return (new DeclarationLineResource($line))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/intrastat/declarations/{id}/lines/{lineId}', operationId: 'updateIntrastatDeclarationLine', description: 'Change the columns of a row. The section of a row never changes.', summary: 'Update INTRA row', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IntrastatLineRequest')), tags: ['Intrastat'], parameters: [new OA\Parameter(ref: '#/components/parameters/IntrastatId'), new OA\Parameter(ref: '#/components/parameters/IntrastatLineId')], responses: [
        new OA\Response(response: 200, description: 'Row updated', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatDeclarationLineResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/IntrastatUnprocessable', response: 422),
    ])]
    public function update(DeclarationLineRequest $request, string $id, string $lineId): DeclarationLineResource|JsonResponse
    {
        $declaration = $this->editableDeclaration($id);

        if ($declaration instanceof JsonResponse) {
            return $declaration;
        }

        $line = $declaration->lines()->findOrFail($lineId);
        $line->update($request->validated());
        $declaration->markEdited();

        return new DeclarationLineResource($line);
    }

    #[OA\Delete(path: '/api/v1/intrastat/declarations/{id}/lines/{lineId}', operationId: 'deleteIntrastatDeclarationLine', description: 'Remove a row; the following rows of the section are renumbered.', summary: 'Delete INTRA row', security: [['bearerAuth' => []]], tags: ['Intrastat'], parameters: [new OA\Parameter(ref: '#/components/parameters/IntrastatId'), new OA\Parameter(ref: '#/components/parameters/IntrastatLineId')], responses: [
        new OA\Response(response: 204, description: 'Row deleted'),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/IntrastatNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/IntrastatUnprocessable', response: 422),
    ])]
    public function destroy(string $id, string $lineId): Response|JsonResponse
    {
        $declaration = $this->editableDeclaration($id);

        if ($declaration instanceof JsonResponse) {
            return $declaration;
        }

        $line = $declaration->lines()->findOrFail($lineId);

        DB::transaction(function () use ($declaration, $line): void {
            $line->delete();

            $declaration->lines()->where('section', $line->section)->where('line_number', '>', $line->line_number)->orderBy('line_number')->get()
                ->each(static fn (IntrastatDeclarationLine $following) => $following->update(['line_number' => $following->line_number - 1]));

            $declaration->markEdited();
        });

        return response()->noContent();
    }

    private function editableDeclaration(string $id): IntrastatDeclaration|JsonResponse
    {
        $declaration = IntrastatDeclaration::query()->findOrFail($id);
        $this->authorize('update', $declaration);

        if (! $declaration->status->isEditable()) {
            return $this->problemDetailsResponse('INTRA list submitted', 'A submitted list cannot change: enter corrections in sections 2 and 4 of a later list.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $declaration;
    }
}
