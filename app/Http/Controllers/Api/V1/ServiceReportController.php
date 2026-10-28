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
use Symfony\Component\HttpFoundation\Response;

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
        $reports = $query->paginate($perPage);

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
    public function uploadPhoto(UploadServiceReportPhotoRequest $request, string $id): JsonResponse
    {
        $report = ServiceReport::findOrFail($id);

        $this->authorize('uploadPhoto', $report);

        $file = $request->file('photo');
        $stage = $request->input('stage', 'general');
        $extension = $file->getClientOriginalExtension();
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
