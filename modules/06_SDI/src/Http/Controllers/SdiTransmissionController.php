<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EiOutboundLog;
use App\Models\InvoiceSdiMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * History of the invoices handed to SDI: status of each transmission, the last provider error and whether it can be sent
 * again. A retry is the normal send of the invoice (`POST /sales/invoices/{id}/send-sdi`), which accepts a failed one.
 */
final class SdiTransmissionController extends Controller
{
    use ApiResponse;

    private const array Statuses = ['draft', 'sending', 'sent', 'delivered', 'accepted', 'rejected', 'not_delivered', 'failed'];

    /**
     * List the SDI transmissions.
     */
    #[OA\Get(
        path: '/api/v1/sdi/transmissions',
        operationId: 'listSdiTransmissions',
        description: 'Invoices transmitted to SDI, latest first, paginated, with the SDI status, identifier, file name and the last provider error of a failed one. `can_retry` is true for a failed transmission: send the invoice again with the send-sdi endpoint. Filter by `status` or `search` (invoice number).',
        summary: 'List SDI transmissions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['SDI'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'sending', 'sent', 'delivered', 'accepted', 'rejected', 'not_delivered', 'failed'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Transmissions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', self::Statuses)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = InvoiceSdiMetadata::query()
            ->with('invoice:id,invoice_number,date,total_gross,currency')
            ->whereHas('invoice', fn ($query) => $query
                ->when($filters['search'] ?? null, fn ($inner, string $search) => $inner->where('invoice_number', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('sdi_status', $status))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        $errors = EiOutboundLog::query()
            ->whereIn('invoice_id', $page->getCollection()->pluck('invoice_id'))
            ->where('status', 'failed')
            ->orderBy('created_at')
            ->get()
            ->groupBy('invoice_id')
            ->map(fn ($logs) => $logs->last()->error);

        $rows = $page->getCollection()->map(fn (InvoiceSdiMetadata $metadata): array => [
            'id' => (string) $metadata->id,
            'invoice_id' => (string) $metadata->invoice_id,
            'invoice_number' => $metadata->invoice?->invoice_number,
            'invoice_date' => $metadata->invoice?->date->toDateString(),
            'total_gross' => $metadata->invoice?->total_gross,
            'currency' => $metadata->invoice?->currency,
            'sdi_status' => $metadata->sdi_status,
            'sdi_identifier' => $metadata->sdi_identifier,
            'file_name' => $metadata->file_name,
            'sent_at' => $metadata->sent_at?->toIso8601String(),
            'delivered_at' => $metadata->delivered_at?->toIso8601String(),
            'last_error' => $metadata->sdi_status === 'failed' ? ($errors[$metadata->invoice_id] ?? null) : null,
            'can_retry' => $metadata->sdi_status === 'failed',
        ])->all();

        return $this->successResponse($rows, 'SDI transmissions retrieved', meta: ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]);
    }
}
