<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\DigitalPreservation\Application\Commands\CreatePreservationBatchCommand;
use Modules\DigitalPreservation\Application\Commands\DispatchToConservatorCommand;
use Modules\DigitalPreservation\Application\Commands\GenerateDistributionPackageCommand;
use Modules\DigitalPreservation\Application\Commands\SealAndTimestampBatchCommand;
use Modules\DigitalPreservation\Application\Commands\VerifyPreservationIntegrityCommand;
use Modules\DigitalPreservation\Application\Handlers\CreatePreservationBatchHandler;
use Modules\DigitalPreservation\Application\Handlers\DispatchToConservatorHandler;
use Modules\DigitalPreservation\Application\Handlers\GenerateDistributionPackageHandler;
use Modules\DigitalPreservation\Application\Handlers\GetPreservationPackageDetailHandler;
use Modules\DigitalPreservation\Application\Handlers\ListPreservationBatchesHandler;
use Modules\DigitalPreservation\Application\Handlers\SealAndTimestampBatchHandler;
use Modules\DigitalPreservation\Application\Handlers\VerifyPreservationIntegrityHandler;
use Modules\DigitalPreservation\Application\Queries\GetPreservationPackageDetailQuery;
use Modules\DigitalPreservation\Application\Queries\ListPreservationBatchesQuery;
use Modules\DigitalPreservation\Domain\Enums\DocumentCategory;
use Modules\DigitalPreservation\Domain\Enums\JurisdictionMode;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Presentation\Http\Requests\CreatePreservationBatchRequest;
use Modules\DigitalPreservation\Presentation\Http\Resources\PreservationBatchResource;
use Modules\DigitalPreservation\Presentation\Http\Resources\PreservationItemResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class PreservationBatchController extends PreservationApiController
{
    #[OA\Get(path: '/api/v1/preservation/batches', operationId: 'listPreservationBatches', description: 'Preservation batches (PdV/PdA) of the tenant, newest first.', summary: 'List preservation batches', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'jurisdiction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['IT', 'CH', 'DUAL'])),
        new OA\Parameter(name: 'document_category', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ], responses: [new OA\Response(response: 200, description: 'The batches', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function index(Request $request, ListPreservationBatchesHandler $handler): JsonResponse
    {
        $page = $handler->handle(new ListPreservationBatchesQuery(
            $request->query('status') ? (string) $request->query('status') : null,
            $request->query('jurisdiction') ? (string) $request->query('jurisdiction') : null,
            $request->query('document_category') ? (string) $request->query('document_category') : null,
            $request->query('search') ? (string) $request->query('search') : null,
            (int) $request->query('per_page', 25),
        ));

        return $this->page($page, PreservationBatchResource::class);
    }

    #[OA\Post(path: '/api/v1/preservation/batches', operationId: 'createPreservationBatch', description: 'Collects the unpreserved documents of a category and period into a draft batch, converting non-archival files to PDF/A.', summary: 'Create a preservation batch', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], responses: [
        new OA\Response(response: 201, description: 'The batch', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Nothing to preserve or invalid input', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function store(CreatePreservationBatchRequest $request, CreatePreservationBatchHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(function () use ($data, $handler): JsonResponse {
            $batch = $handler->handle(new CreatePreservationBatchCommand(
                DocumentCategory::from($data['document_category']), Carbon::parse($data['period_from']), Carbon::parse($data['period_to']),
                isset($data['jurisdiction']) ? JurisdictionMode::from($data['jurisdiction']) : null,
            ));

            return $this->successResponse((new PreservationBatchResource($batch))->resolve(), 'Batch created', Response::HTTP_CREATED);
        });
    }

    #[OA\Get(path: '/api/v1/preservation/batches/{id}', operationId: 'getPreservationBatch', description: 'Batch with PdV.xml, items, time-stamp metadata and RdV receipt.', summary: 'Get a preservation batch', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [new OA\Response(response: 200, description: 'The batch', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function show(string $id, GetPreservationPackageDetailHandler $handler): JsonResponse
    {
        $detail = $handler->handle(new GetPreservationPackageDetailQuery($id));
        $this->authorize('view', $detail['batch']);

        return $this->successResponse(
            (new PreservationBatchResource($detail['batch']))->resolve() + [
                'pdv_xml' => $detail['pdv_xml'], 'rdv_xml' => $detail['rdv_xml'], 'timestamp' => $detail['timestamp'],
                'items' => PreservationItemResource::collection($detail['batch']->items->each->setRelation('batch', $detail['batch']))->resolve(),
            ],
        );
    }

    #[OA\Post(path: '/api/v1/preservation/batches/{id}/seal', operationId: 'sealPreservationBatch', description: 'Generates the PdV.xml, computes the Merkle root and applies the RFC 3161 time stamp.', summary: 'Seal and time-stamp a batch', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [new OA\Response(response: 200, description: 'The batch', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 422, description: 'Rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function seal(string $id, SealAndTimestampBatchHandler $handler): JsonResponse
    {
        $this->authorize('manage', PreservationBatch::findOrFail($id));

        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            (new PreservationBatchResource($handler->handle(new SealAndTimestampBatchCommand($id, (string) auth()->id()))))->resolve(), 'Batch sealed',
        ));
    }

    #[OA\Post(path: '/api/v1/preservation/batches/{id}/dispatch', operationId: 'dispatchPreservationBatch', description: 'Sends the sealed PdV to the configured conservator and records the RdV.', summary: 'Dispatch a batch to the conservator', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [new OA\Response(response: 200, description: 'The batch', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 502, description: 'Conservator error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function dispatch(string $id, DispatchToConservatorHandler $handler): JsonResponse
    {
        $this->authorize('manage', PreservationBatch::findOrFail($id));

        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            (new PreservationBatchResource($handler->handle(new DispatchToConservatorCommand($id))))->resolve(), 'Batch dispatched',
        ));
    }

    #[OA\Post(path: '/api/v1/preservation/batches/{id}/verify', operationId: 'verifyPreservationBatch', description: 'Live re-verification of all files, hashes, Merkle root, chain and time stamp.', summary: 'Verify a batch', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [new OA\Response(response: 200, description: 'The report', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function verify(string $id, VerifyPreservationIntegrityHandler $handler): JsonResponse
    {
        $this->authorize('view', PreservationBatch::findOrFail($id));

        return $this->successResponse($handler->handle(new VerifyPreservationIntegrityCommand(batchId: $id)));
    }

    #[OA\Get(path: '/api/v1/preservation/batches/{id}/export-pdd', operationId: 'exportPreservationBatchPdd', description: 'Downloads the judicial distribution package (PdD) of the batch as a ZIP.', summary: 'Export the PdD of a batch', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [new OA\Response(response: 200, description: 'ZIP package', content: new OA\MediaType(mediaType: 'application/zip')), new OA\Response(response: 422, description: 'Batch not sealed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function exportPdd(string $id, GenerateDistributionPackageHandler $handler): mixed
    {
        $this->authorize('view', PreservationBatch::findOrFail($id));

        try {
            $package = $handler->handle(new GenerateDistributionPackageCommand(batchId: $id));
        } catch (PreservationException $exception) {
            return $this->problemDetailsResponse('Preservation Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->download($package['path'], $package['name'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
