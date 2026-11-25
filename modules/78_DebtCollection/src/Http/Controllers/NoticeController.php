<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\DebtCollection\Application\Commands\CancelNoticeCommand;
use Modules\DebtCollection\Application\Commands\CancelNoticeHandler;
use Modules\DebtCollection\Application\Commands\CreateBatchCommand;
use Modules\DebtCollection\Application\Commands\CreateBatchHandler;
use Modules\DebtCollection\Application\Commands\RecordReceiptCommand;
use Modules\DebtCollection\Application\Commands\RecordReceiptHandler;
use Modules\DebtCollection\Application\Commands\RetryBatchCommand;
use Modules\DebtCollection\Application\Commands\RetryBatchHandler;
use Modules\DebtCollection\Application\Commands\SendNoticeCommand;
use Modules\DebtCollection\Application\Commands\SendNoticeHandler;
use Modules\DebtCollection\Http\Controllers\Concerns\ResolvesDebtCollectionContext;
use Modules\DebtCollection\Http\Requests\CreateBatchRequest;
use Modules\DebtCollection\Http\Requests\NoticeIndexRequest;
use Modules\DebtCollection\Http\Requests\ReceiptRequest;
use Modules\DebtCollection\Http\Requests\SendNoticeRequest;
use Modules\DebtCollection\Http\Resources\BatchResource;
use Modules\DebtCollection\Http\Resources\NoticeResource;
use Modules\DebtCollection\Models\Batch;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Services\ListingService;
use Modules\DebtCollection\Services\NoticeExportService;
use Modules\DebtCollection\Services\NoticeRenderer;
use OpenApi\Attributes as OA;

/**
 * Notices (reminders and messa in mora): list, read, download the PDF and the export package, send on a channel,
 * cancel, record PEC receipts, and the bulk dispatch batches with their progress.
 */
final class NoticeController extends Controller
{
    use ApiResponse;
    use ResolvesDebtCollectionContext;

    public function __construct(
        private readonly ListingService $listing,
        private readonly SendNoticeHandler $send,
        private readonly CancelNoticeHandler $cancel,
        private readonly RecordReceiptHandler $receipt,
        private readonly CreateBatchHandler $createBatch,
        private readonly RetryBatchHandler $retryBatch,
        private readonly NoticeRenderer $renderer,
        private readonly NoticeExportService $export,
    ) {}

    #[OA\Get(path: '/api/v1/debt-collection/notices', operationId: 'debtCollectionListNotices', summary: 'List the dunning notices', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Notices')])]
    public function index(NoticeIndexRequest $request): JsonResponse
    {
        $page = $this->listing->notices($request->validated(), (int) ($request->validated('per_page') ?? 25));

        return $this->successResponse(NoticeResource::collection($page->items()), 'OK', 200, $this->pageMeta($page));
    }

    #[OA\Get(path: '/api/v1/debt-collection/notices/{id}', operationId: 'debtCollectionShowNotice', summary: 'Show a notice with its lines and PEC receipts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Notice')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);

        return $this->successResponse(new NoticeResource(Notice::query()->with(['customer', 'lines', 'receipts'])->findOrFail($id)));
    }

    #[OA\Get(path: '/api/v1/debt-collection/notices/{id}/pdf', operationId: 'debtCollectionNoticePdf', summary: 'Download the notice as a PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF document')])]
    public function pdf(string $id): Response
    {
        Gate::authorize('viewAny', Notice::class);
        $notice = Notice::query()->with('lines')->findOrFail($id);

        return response($this->renderer->pdf($notice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$notice->number.'.pdf"']);
    }

    #[OA\Get(path: '/api/v1/debt-collection/notices/{id}/export', operationId: 'debtCollectionNoticeExport', summary: 'Download the export package (PDF, text, receipts manifest) of a notice', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'ZIP archive')])]
    public function exportPackage(string $id): Response
    {
        Gate::authorize('viewAny', Notice::class);
        $notice = Notice::query()->findOrFail($id);

        return response($this->export->package($notice), 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="'.$notice->number.'.zip"']);
    }

    #[OA\Post(path: '/api/v1/debt-collection/notices/{id}/send', operationId: 'debtCollectionSendNotice', summary: 'Send a notice on its channel', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Notice after the attempt')])]
    public function send(SendNoticeRequest $request, string $id): JsonResponse
    {
        $notice = $this->send->handle(new SendNoticeCommand($id, $request->validated('channel'), $this->userId($request)));

        return $this->successResponse(new NoticeResource($notice->load('customer')));
    }

    #[OA\Post(path: '/api/v1/debt-collection/notices/{id}/cancel', operationId: 'debtCollectionCancelNotice', summary: 'Cancel a notice that was not sent', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Cancelled notice')])]
    public function cancel(SendNoticeRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new NoticeResource($this->cancel->handle(new CancelNoticeCommand($id, $this->userId($request)))->load('customer')));
    }

    #[OA\Post(path: '/api/v1/debt-collection/notices/{id}/pec-receipts', operationId: 'debtCollectionRecordReceipt', summary: 'Record a PEC acceptance or delivery receipt', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Notice with its receipts')])]
    public function recordReceipt(ReceiptRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        $this->receipt->handle(new RecordReceiptCommand($id, $data['kind'], $data['message_id'] ?? null, $data['received_at'] ?? null, $data['notes'] ?? null, $this->userId($request)));

        return $this->successResponse(new NoticeResource(Notice::query()->with(['customer', 'lines', 'receipts'])->findOrFail($id)), 'Receipt recorded', 201);
    }

    #[OA\Get(path: '/api/v1/debt-collection/batches', operationId: 'debtCollectionListBatches', summary: 'List dispatch batches', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 200, description: 'Batches')])]
    public function batches(NoticeIndexRequest $request): JsonResponse
    {
        $page = $this->listing->batches((int) ($request->validated('per_page') ?? 25));

        return $this->successResponse(BatchResource::collection($page->items()), 'OK', 200, $this->pageMeta($page));
    }

    #[OA\Post(path: '/api/v1/debt-collection/batches', operationId: 'debtCollectionCreateBatch', summary: 'Dispatch notices in bulk', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], responses: [new OA\Response(response: 201, description: 'Batch')])]
    public function storeBatch(CreateBatchRequest $request): JsonResponse
    {
        $batch = $this->createBatch->handle(new CreateBatchCommand($this->tenantId(), $request->validated('notice_ids'), $request->validated('channel'), $this->userId($request)));

        return $this->successResponse(new BatchResource($batch), 'Batch queued', 201);
    }

    #[OA\Get(path: '/api/v1/debt-collection/batches/{id}', operationId: 'debtCollectionShowBatch', summary: 'Show a batch with its notices', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Batch')])]
    public function showBatch(string $id): JsonResponse
    {
        Gate::authorize('viewAny', Notice::class);

        return $this->successResponse(new BatchResource(Batch::query()->with('notices.customer')->findOrFail($id)));
    }

    #[OA\Post(path: '/api/v1/debt-collection/batches/{id}/retry', operationId: 'debtCollectionRetryBatch', summary: 'Queue the failed notices of a batch again', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Debt Collection'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Batch')])]
    public function retryBatch(SendNoticeRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new BatchResource($this->retryBatch->handle(new RetryBatchCommand($this->tenantId(), $id, $this->userId($request)))));
    }
}
