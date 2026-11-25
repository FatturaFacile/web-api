<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\TenderManagement\Application\Commands\UploadEnvelopeDocumentCommand;
use Modules\TenderManagement\Application\Commands\UploadEnvelopeDocumentHandler;
use Modules\TenderManagement\Http\Controllers\Concerns\InteractsWithTenders;
use Modules\TenderManagement\Http\Requests\SaveChecklistRequest;
use Modules\TenderManagement\Http\Requests\UploadEnvelopeDocumentRequest;
use Modules\TenderManagement\Http\Resources\ParticipationResource;
use Modules\TenderManagement\Services\EnvelopeService;
use OpenApi\Attributes as OA;

/**
 * The three envelopes of a dossier: checklist definition, document upload with signature check and removal.
 */
final class EnvelopeController extends Controller
{
    use ApiResponse;
    use InteractsWithTenders;

    #[OA\Put(path: '/api/v1/tender-management/participations/{id}/envelopes/{envelope}', operationId: 'tenderManagementDefineEnvelope', summary: 'Define the checklist of an envelope', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'envelope', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Dossier with the new checklist')])]
    public function define(SaveChecklistRequest $request, string $id, string $envelope, EnvelopeService $envelopes): JsonResponse
    {
        $participation = $this->findParticipation($id, 'update');
        $envelopes->define($participation, $envelope, $request->validated('items'));

        return $this->successResponse(new ParticipationResource($participation->refresh()->load('notice')), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/tender-management/participations/{id}/envelopes/{envelope}/seed', operationId: 'tenderManagementSeedEnvelope', summary: 'Load the default checklist of the jurisdiction', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'envelope', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Dossier with the default checklist')])]
    public function seed(string $id, string $envelope, EnvelopeService $envelopes): JsonResponse
    {
        $participation = $this->findParticipation($id, 'update');
        $envelopes->seed($participation, $envelope);

        return $this->successResponse(new ParticipationResource($participation->refresh()->load('notice')), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/tender-management/participations/{id}/envelopes/{envelope}/documents', operationId: 'tenderManagementUploadEnvelopeDocument', summary: 'Upload an envelope document and check its .p7m / PAdES signature', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'envelope', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 201, description: 'The updated checklist item')])]
    public function upload(UploadEnvelopeDocumentRequest $request, string $id, string $envelope, UploadEnvelopeDocumentHandler $handler): JsonResponse
    {
        $this->findParticipation($id, 'update');

        $item = $handler->handle(new UploadEnvelopeDocumentCommand($id, $envelope, (string) $request->input('doc_key'), $request->file('file')));

        return $this->successResponse($item, $this->message('uploaded'), 201);
    }

    #[OA\Delete(path: '/api/v1/tender-management/participations/{id}/envelopes/{envelope}/documents/{docKey}', operationId: 'tenderManagementRemoveEnvelopeDocument', summary: 'Remove the file of an envelope document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'envelope', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'docKey', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'File removed')])]
    public function remove(string $id, string $envelope, string $docKey, EnvelopeService $envelopes): JsonResponse
    {
        $participation = $this->findParticipation($id, 'update');
        $envelopes->remove($participation, $envelope, $docKey);

        return $this->successResponse(null, $this->message('deleted'));
    }
}
