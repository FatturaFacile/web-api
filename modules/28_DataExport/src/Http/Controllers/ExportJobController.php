<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\DataExport\Http\Requests\StoreExportJobRequest;
use Modules\DataExport\Http\Resources\ExportJobResource;
use Modules\DataExport\Models\ExportJob;
use Modules\DataExport\Services\ExportJobService;
use OpenApi\Attributes as OA;

/**
 * History of the exports of the company: request an export (it runs in the queue), follow its status, get a signed link, retry a failure.
 */
final class ExportJobController extends Controller
{
    use ApiResponse;

    private const int HistoryLimit = 100;

    public function __construct(private readonly ExportJobService $service) {}

    /**
     * List the export history.
     */
    #[OA\Get(
        path: '/api/v1/exports/jobs',
        operationId: 'listExportJobs',
        description: 'The latest exports of the company, newest first (at most 100), optionally filtered by status or dataset.',
        summary: 'List export history',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Data Export'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['queued', 'running', 'completed', 'failed'])),
            new OA\Parameter(name: 'dataset', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['invoices', 'purchases', 'payments', 'contacts', 'accountant_archive'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Export history', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExportJobResource'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:queued,running,completed,failed'],
            'dataset' => ['nullable', 'string', 'in:'.implode(',', ExportJob::Datasets)],
        ]);

        $jobs = ExportJob::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['dataset'] ?? null, fn ($query, string $dataset) => $query->where('dataset', $dataset))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HistoryLimit)
            ->get();

        return $this->successResponse(ExportJobResource::collection($jobs));
    }

    /**
     * Request an export.
     */
    #[OA\Post(
        path: '/api/v1/exports/jobs',
        operationId: 'createExportJob',
        description: 'Queues an export and answers at once with the job in `queued` status; poll the job or the history for the outcome. The accountant archive needs `from` and `to` within one year.',
        summary: 'Request an export',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreExportJobRequest')),
        tags: ['Data Export'],
        responses: [
            new OA\Response(response: 202, description: 'Queued', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/ExportJobResource')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreExportJobRequest $request): JsonResponse
    {
        $job = $this->service->queue($request->validated('dataset'), $request->validated('from'), $request->validated('to'), (string) $request->user()->id);

        return $this->successResponse(new ExportJobResource($job), 'Export queued', Response::HTTP_ACCEPTED);
    }

    /**
     * Show one export.
     */
    #[OA\Get(
        path: '/api/v1/exports/jobs/{id}',
        operationId: 'showExportJob',
        description: 'Status, size and row count of one export.',
        summary: 'Show an export',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Data Export'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The export', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/ExportJobResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new ExportJobResource(ExportJob::query()->findOrFail($id)));
    }

    /**
     * Get a signed download link.
     */
    #[OA\Get(
        path: '/api/v1/exports/jobs/{id}/link',
        operationId: 'getExportJobDownloadLink',
        description: 'A signed link to the stored file, valid for one hour. The link is the credential: open it without a token. 409 when the export is not completed or its file has expired.',
        summary: 'Get a download link',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Data Export'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The link', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'url', type: 'string', format: 'uri'), new OA\Property(property: 'expires_in_minutes', type: 'integer')])])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Not downloadable', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function link(string $id): JsonResponse
    {
        $job = ExportJob::query()->findOrFail($id);

        if (! $job->isDownloadable()) {
            return $this->problemDetailsResponse('Export not downloadable', 'The export is not completed or its file has expired.', Response::HTTP_CONFLICT);
        }

        return $this->successResponse(['url' => $this->service->downloadUrl($job), 'expires_in_minutes' => ExportJobService::LinkMinutes]);
    }

    /**
     * Retry a failed export.
     */
    #[OA\Post(
        path: '/api/v1/exports/jobs/{id}/retry',
        operationId: 'retryExportJob',
        description: 'Queues a failed export again with the same dataset and period. 409 when the export has not failed.',
        summary: 'Retry a failed export',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Data Export'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 202, description: 'Queued again', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/ExportJobResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The export has not failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function retry(string $id): JsonResponse
    {
        $job = ExportJob::query()->findOrFail($id);

        if ($job->status !== ExportJob::Failed) {
            return $this->problemDetailsResponse('Export not failed', 'Only a failed export can be retried.', Response::HTTP_CONFLICT);
        }

        return $this->successResponse(new ExportJobResource($this->service->retry($job)), 'Export queued', Response::HTTP_ACCEPTED);
    }
}
