<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\DigitalPreservation\Application\Commands\GenerateDistributionPackageCommand;
use Modules\DigitalPreservation\Application\Commands\VerifyPreservationIntegrityCommand;
use Modules\DigitalPreservation\Application\Handlers\GenerateDistributionPackageHandler;
use Modules\DigitalPreservation\Application\Handlers\SearchPreservationItemsHandler;
use Modules\DigitalPreservation\Application\Handlers\VerifyPreservationIntegrityHandler;
use Modules\DigitalPreservation\Application\Queries\SearchPreservationItemsQuery;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\DigitalPreservation\Presentation\Http\Resources\PreservationItemResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class PreservationItemController extends PreservationApiController
{
    #[OA\Get(path: '/api/v1/preservation/items', operationId: 'searchDigitalPreservationItems', description: 'Compliance search over the preserved documents by UUID, number, counterparty, tax id, hash, date range, category and jurisdiction.', summary: 'Search preserved documents', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], parameters: [
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'sha256', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [new OA\Response(response: 200, description: 'The items', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function index(Request $request, SearchPreservationItemsHandler $handler): JsonResponse
    {
        $str = static fn (string $key): ?string => $request->query($key) ? (string) $request->query($key) : null;

        $page = $handler->handle(new SearchPreservationItemsQuery(
            $str('search'), $str('document_number'), $str('counterparty'), $str('tax_id'), $str('sha256'), $str('date_from'), $str('date_to'),
            $str('document_category'), $str('jurisdiction'), (int) $request->query('per_page', 25),
        ));

        return $this->page($page, PreservationItemResource::class);
    }

    #[OA\Post(path: '/api/v1/preservation/items/{id}/verify', operationId: 'verifyPreservationItem', description: 'Live SHA-256 check of one preserved file against the vault.', summary: 'Verify a preserved document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [new OA\Response(response: 200, description: 'The result', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function verify(string $id, VerifyPreservationIntegrityHandler $handler): JsonResponse
    {
        $this->authorize('view', PreservationBatchItem::findOrFail($id));

        return $this->successResponse($handler->handle(new VerifyPreservationIntegrityCommand(itemId: $id)));
    }

    #[OA\Get(path: '/api/v1/preservation/items/{id}/export-pdd', operationId: 'exportPreservationItemPdd', description: 'Downloads the judicial exhibition package (PdD) of a single document as a ZIP.', summary: 'Export the PdD of a document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [new OA\Response(response: 200, description: 'ZIP package', content: new OA\MediaType(mediaType: 'application/zip'))])]
    public function exportPdd(string $id, GenerateDistributionPackageHandler $handler): mixed
    {
        $this->authorize('view', PreservationBatchItem::findOrFail($id));

        try {
            $package = $handler->handle(new GenerateDistributionPackageCommand(itemId: $id));
        } catch (PreservationException $exception) {
            return $this->problemDetailsResponse('Preservation Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->download($package['path'], $package['name'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
