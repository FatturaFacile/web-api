<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Procurement;

use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\Rfq\StoreRfqAttachmentRequest;
use App\Http\Requests\Procurement\Rfq\UpdateRfqAttachmentRequest;
use App\Http\Resources\Procurement\Rfq\RfqAttachmentResource;
use App\Infrastructure\Procurement\Rfq\Services\RfqAttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Buyer management of technical specifications, drawings, and BOM attachments on an RFQ.
 */
#[OA\Tag(name: 'Procurement RFQ', description: 'Requests for quotation, supplier bidding, comparison matrix, and awarding')]
final class RfqAttachmentController extends Controller
{
    public function __construct(
        private readonly RfqAttachmentStorageService $storage,
    ) {}

    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{rfq}/attachments',
        operationId: 'procurementRfqsAttachmentsStore',
        summary: 'Upload technical attachment to RFQ',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 201, description: 'Attachment uploaded'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(StoreRfqAttachmentRequest $request, string $rfq): JsonResponse
    {
        $model = RequestForQuotation::query()->findOrFail($rfq);
        $this->authorize('update', $model);

        $attachment = $this->storage->storeRfqAttachment(
            rfq: $model,
            file: $request->file('file'),
            isVisibleToSuppliers: $request->boolean('is_visible_to_suppliers', true),
        );

        return (new RfqAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/procurement/rfqs/{rfq}/attachments/{attachment}',
        operationId: 'procurementRfqsAttachmentsUpdate',
        summary: 'Toggle supplier visibility on RFQ attachment',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'Attachment updated'),
        ]
    )]
    public function update(UpdateRfqAttachmentRequest $request, string $rfq, string $attachment): RfqAttachmentResource
    {
        $model = RequestForQuotation::query()->findOrFail($rfq);
        $this->authorize('update', $model);

        $record = RfqAttachment::query()->where('rfq_id', $model->id)->findOrFail($attachment);
        $record->update(['is_visible_to_suppliers' => $request->boolean('is_visible_to_suppliers')]);

        return new RfqAttachmentResource($record);
    }

    #[OA\Get(
        path: '/api/v1/procurement/rfqs/{rfq}/attachments/{attachment}/download',
        operationId: 'procurementRfqsAttachmentsDownload',
        summary: 'Download RFQ attachment',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'Attachment binary'),
        ]
    )]
    public function download(string $rfq, string $attachment): StreamedResponse
    {
        $model = RequestForQuotation::query()->findOrFail($rfq);
        $this->authorize('view', $model);

        $record = RfqAttachment::query()->where('rfq_id', $model->id)->findOrFail($attachment);

        return $this->storage->streamRfqAttachment($record);
    }

    #[OA\Delete(
        path: '/api/v1/procurement/rfqs/{rfq}/attachments/{attachment}',
        operationId: 'procurementRfqsAttachmentsDestroy',
        summary: 'Delete RFQ attachment',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
        ]
    )]
    public function destroy(string $rfq, string $attachment): Response
    {
        $model = RequestForQuotation::query()->findOrFail($rfq);
        $this->authorize('update', $model);

        $record = RfqAttachment::query()->where('rfq_id', $model->id)->findOrFail($attachment);
        $this->storage->deleteAttachment($record);

        return response()->noContent();
    }
}
