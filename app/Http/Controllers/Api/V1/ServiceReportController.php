<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ServiceReports\GenerateConsolidatedInvoiceAction;
use App\Actions\ServiceReports\GenerateInvoiceFromServiceReportAction;
use App\Actions\ServiceReports\SaveServiceReport;
use App\Actions\ServiceReports\SignServiceReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceReports\GenerateConsolidatedInvoiceRequest;
use App\Http\Requests\ServiceReports\SignServiceReportRequest;
use App\Http\Requests\ServiceReports\StoreServiceReportRequest;
use App\Http\Requests\ServiceReports\UpdateServiceReportRequest;
use App\Http\Requests\ServiceReports\UploadServiceReportPhotoRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\ServiceReportResource;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Models\ServiceReport;
use App\Services\Pdf\DocumentPdfService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Service Reports', description: 'Field service reports, signatures, PDF export, and invoicing')]
class ServiceReportController extends Controller
{
    use ApiResponse, AuthorizesRequests;

    public function __construct(
        private readonly SaveServiceReport $saveReport,
        private readonly SignServiceReportAction $signReport,
        private readonly GenerateInvoiceFromServiceReportAction $invoiceAction,
        private readonly GenerateConsolidatedInvoiceAction $consolidatedInvoiceAction,
        private readonly DocumentPdfService $pdfService,
    ) {}

    /**
     * List paginated service reports with optional filtering.
     */
    #[OA\Get(
        path: '/api/v1/service-reports',
        summary: 'List service reports',
        description: 'Get paginated list of service reports with optional filtering',
        tags: ['Service Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'contact_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'technician_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'uninvoiced', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'portal_visible', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service reports retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceReport::class);

        $query = ServiceReport::with([
            'contact',
            'technician',
            'status.translations',
            'invoiceLink',
        ])->latest('report_date');

        if ($request->filled('status')) {
            $query->whereHas('status', fn ($q) => $q->where('code', $request->query('status')));
        }

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->query('contact_id'));
        }

        if ($request->filled('technician_id')) {
            $query->where('technician_id', $request->query('technician_id'));
        }

        if ($request->filled('from_date')) {
            $query->where('report_date', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('report_date', '<=', $request->query('to_date'));
        }

        if ($request->boolean('uninvoiced')) {
            $query->uninvoiced();
        }

        if ($request->boolean('portal_visible')) {
            $query->portalVisible();
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $reports = $query->gridPaginate($perPage);

        return $this->successResponse(
            data: ServiceReportResource::collection($reports),
            message: 'Service reports retrieved successfully',
            meta: [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
            ]
        );
    }

    /**
     * Store a newly created service report with lines.
     */
    #[OA\Post(
        path: '/api/v1/service-reports',
        summary: 'Create service report',
        description: 'Store a newly created service report with line items',
        tags: ['Service Reports'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['contact_id', 'report_date'],
                properties: [
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'technician_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'report_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'notes', type: 'string'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Service report created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(StoreServiceReportRequest $request): JsonResponse
    {
        $this->authorize('create', ServiceReport::class);

        $report = $this->saveReport->create($request->validated());

        return $this->successResponse(
            data: new ServiceReportResource($report),
            message: 'Service report created successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Retrieve a specific service report with lines, signatures, and attachments.
     */
    #[OA\Get(
        path: '/api/v1/service-reports/{id}',
        summary: 'Get service report details',
        description: 'Retrieve a specific service report with lines, signatures, and attachments',
        tags: ['Service Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service report retrieved successfully'),
            new OA\Response(response: 404, description: 'Service report not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $report = ServiceReport::with([
            'lines.lineType.translations',
            'lines.item',
            'lines.vatCode',
            'signatures',
            'contact',
            'technician',
            'status.translations',
            'attachments',
            'invoiceLink',
        ])->findOrFail($id);

        $this->authorize('view', $report);

        return $this->successResponse(
            data: new ServiceReportResource($report),
            message: 'Service report details retrieved successfully'
        );
    }

    /**
     * Update an existing service report and its lines.
     */
    #[OA\Patch(
        path: '/api/v1/service-reports/{id}',
        operationId: 'patchServiceReport',
        description: 'Updates an existing service report of the active tenant.',
        summary: 'Update service report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Service Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the service report', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateServiceReportRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceReportResource')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[OA\Put(
        path: '/api/v1/service-reports/{id}',
        summary: 'Update service report',
        description: 'Update an existing service report and its line items',
        tags: ['Service Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'report_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'notes', type: 'string'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Service report updated successfully'),
            new OA\Response(response: 404, description: 'Service report not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function update(UpdateServiceReportRequest $request, string $id): JsonResponse
    {
        $report = ServiceReport::findOrFail($id);

        $this->authorize('update', $report);

        $updated = $this->saveReport->update($report, $request->validated());

        return $this->successResponse(
            data: new ServiceReportResource($updated),
            message: 'Service report updated successfully'
        );
    }

    /**
     * Soft delete a service report (only if unsigned and uninvoiced).
     */
    #[OA\Delete(
        path: '/api/v1/service-reports/{id}',
        summary: 'Delete service report',
        description: 'Soft delete an unsigned, uninvoiced service report',
        tags: ['Service Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service report deleted successfully'),
            new OA\Response(response: 404, description: 'Service report not found'),
            new OA\Response(response: 422, description: 'Signed or invoiced report cannot be deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $report = ServiceReport::findOrFail($id);

        $this->authorize('delete', $report);

        if ($report->signed_at !== null || $report->invoiced_at !== null) {
            return $this->problemDetailsResponse(
                title: 'Cannot Delete Report',
                detail: 'Signed or invoiced service reports cannot be deleted to protect accounting audit trails.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $report->delete();

        return $this->successResponse(
            data: null,
            message: 'Service report deleted successfully'
        );
    }

    /**
     * Sign service report with touchscreen digital signature.
     */
    #[OA\Post(
        path: '/api/v1/service-reports/{id}/sign',
        summary: 'Sign service report',
        description: 'Customer digital signature on service report',
        tags: ['Service Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['signer_name', 'signature_data'],
                properties: [
                    new OA\Property(property: 'signer_name', type: 'string'),
                    new OA\Property(property: 'signature_data', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Service report signed successfully'),
            new OA\Response(response: 404, description: 'Service report not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function sign(SignServiceReportRequest $request, string $id): JsonResponse
    {
        $report = ServiceReport::findOrFail($id);

        $this->authorize('sign', $report);

        $payload = array_merge($request->validated(), [
            'ip_address' => $request->ip() ?? '127.0.0.1',
            'user_agent' => $request->userAgent(),
        ]);

        $signedReport = $this->signReport->execute($report, $payload);

        return $this->successResponse(
            data: new ServiceReportResource($signedReport),
            message: 'Service report signed successfully by customer'
        );
    }

    /**
     * Generate a direct 1-to-1 invoice from a signed service report.
     */
    #[OA\Post(
        path: '/api/v1/service-reports/{id}/generate-invoice',
        summary: 'Generate invoice from service report',
        description: 'Create an invoice directly from a signed service report',
        tags: ['Service Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Invoice generated successfully'),
            new OA\Response(response: 404, description: 'Service report not found'),
            new OA\Response(response: 422, description: 'Unprocessable report'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function generateInvoice(string $id): JsonResponse
    {
        $report = ServiceReport::findOrFail($id);

        $this->authorize('generateInvoice', $report);

        $invoice = $this->invoiceAction->execute($report);

        return $this->successResponse(
            data: new InvoiceResource($invoice),
            message: "Invoice {$invoice->invoice_number} successfully generated from service report",
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Generate a consolidated invoice from multiple signed service reports for the same customer.
     */
    #[OA\Post(
        path: '/api/v1/service-reports/generate-consolidated-invoice',
        summary: 'Generate consolidated invoice',
        description: 'Create a consolidated invoice from multiple signed service reports',
        tags: ['Service Reports'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['report_ids'],
                properties: [
                    new OA\Property(property: 'report_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Consolidated invoice generated successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function generateConsolidatedInvoice(GenerateConsolidatedInvoiceRequest $request): JsonResponse
    {
        $reportIds = $request->validated('report_ids');

        foreach ($reportIds as $reportId) {
            $report = ServiceReport::findOrFail($reportId);
            $this->authorize('generateInvoice', $report);
        }

        $invoice = $this->consolidatedInvoiceAction->execute($reportIds);

        return $this->successResponse(
            data: new InvoiceResource($invoice),
            message: "Consolidated invoice {$invoice->invoice_number} generated successfully",
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Upload before/after photo or work document to the service report.
     */
    #[OA\Post(
        path: '/api/v1/service-reports/{id}/upload-photo',
        summary: 'Upload photo to service report',
        description: 'Upload before/after work photo or attachment',
        tags: ['Service Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['photo'],
                    properties: [
                        new OA\Property(property: 'photo', type: 'string', format: 'binary'),
                        new OA\Property(property: 'stage', type: 'string', enum: ['before', 'after', 'general']),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Photo uploaded successfully'),
            new OA\Response(response: 404, description: 'Service report not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function uploadPhoto(UploadServiceReportPhotoRequest $request, string $id): JsonResponse
    {
        $report = ServiceReport::findOrFail($id);

        $this->authorize('uploadPhoto', $report);

        $file = $request->file('photo');
        $stage = $request->input('stage', 'general');
        $extension = $file->guessExtension() ?: 'jpg';
        $filename = "photo_{$stage}_".time().'_'.bin2hex(random_bytes(4)).".{$extension}";
        $path = "tenants/{$report->tenant_id}/photos/service-reports/{$report->id}/{$filename}";

        $disk = config('filesystems.private_disk', config('filesystems.default', 'local'));
        Storage::disk($disk)->put($path, $file->getContent());

        $attachment = Attachment::create([
            'tenant_id' => $report->tenant_id,
            'attachable_type' => ServiceReport::class,
            'attachable_id' => $report->id,
            'uploaded_by' => Auth::id(),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?? 'image/jpeg',
            'size' => $file->getSize(),
        ]);

        return $this->successResponse(
            data: [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'stage' => $stage,
                'size' => $attachment->size,
                'created_at' => $attachment->created_at?->toISOString(),
            ],
            message: 'Photo uploaded successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * Download or stream the generated service report PDF.
     */
    #[OA\Get(
        path: '/api/v1/service-reports/{id}/pdf',
        summary: 'Download service report PDF',
        description: 'Download or stream rendered service report PDF',
        tags: ['Service Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'inline', in: 'query', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF file stream'),
            new OA\Response(response: 404, description: 'Service report not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function pdf(Request $request, string $id): HttpResponse
    {
        $report = ServiceReport::findOrFail($id);

        $this->authorize('pdf', $report);

        $rendered = $this->pdfService->render($report);
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($rendered->content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$rendered->filename}\"",
            'Content-Length' => (string) strlen($rendered->content),
        ]);
    }
}
