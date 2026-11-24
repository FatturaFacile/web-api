<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Http\Requests\StorePagoPaNoticeRequest;
use Modules\Treasury\Http\Resources\PagoPaNoticeResource;
use Modules\Treasury\Models\PagoPaPaymentNotice;
use Modules\Treasury\Services\PagoPaNoticeService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * pagoPA payment notices of receivables: issue with IUV, notice code and QR payload, cancel, record the payment.
 */
final class PagoPaNoticeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PagoPaNoticeService $notices) {}

    /**
     * The notices of the tenant, newest first.
     */
    #[OA\Get(
        path: '/api/v1/treasury/pagopa-notices',
        operationId: 'listTreasuryPagoPaNotices',
        description: 'Lists the pagoPA payment notices of the active tenant, newest first, optionally by status, paginated; sortable and filterable by column (grid contract).',
        summary: 'List pagoPA notices',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury pagoPA Notices'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'paid', 'expired', 'cancelled'])), new OA\Parameter(ref: '#/components/parameters/TreasuryPerPage')],
        responses: [
            new OA\Response(response: 200, description: 'Notices', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryPagoPaNoticeResource')),
                new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer')]),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:pending,paid,expired,cancelled']])['status'] ?? null;
        $page = PagoPaPaymentNotice::query()->when($status, fn ($query, string $value) => $query->where('status', $value))->latest()->gridPaginate(RequestedPerPage::resolve(25));

        return $this->successResponse(PagoPaNoticeResource::collection($page->items()), 'pagoPA notices', meta: ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    /**
     * Issues a notice.
     */
    #[OA\Post(
        path: '/api/v1/treasury/pagopa-notices',
        operationId: 'createTreasuryPagoPaNotice',
        description: 'Issues a pending pagoPA notice for a receivable. The 13-digit IUV is generated from a per-tenant counter (11-digit progressive number plus 2 check digits) and the 18-digit notice code is derived from it (auxiliary digit 3, application code 02, IUV, 2 check digits).',
        summary: 'Create pagoPA notice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStorePagoPaNoticeRequest')),
        tags: ['Treasury pagoPA Notices'],
        responses: [
            new OA\Response(response: 201, description: 'Notice issued', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryPagoPaNoticeResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function store(StorePagoPaNoticeRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $notice = $this->notices->create((string) $tenantContext->getTenantId(), $request->validated());

        return $this->successResponse(new PagoPaNoticeResource($notice), 'pagoPA notice issued', Response::HTTP_CREATED);
    }

    /**
     * One notice.
     */
    #[OA\Get(
        path: '/api/v1/treasury/pagopa-notices/{id}',
        operationId: 'getTreasuryPagoPaNotice',
        description: 'Returns a pagoPA payment notice.',
        summary: 'Get pagoPA notice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury pagoPA Notices'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryPagoPaNoticeId')],
        responses: [
            new OA\Response(response: 200, description: 'Notice', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryPagoPaNoticeResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new PagoPaNoticeResource(PagoPaPaymentNotice::query()->findOrFail($id)), 'pagoPA notice');
    }

    /**
     * Cancels a pending notice.
     */
    #[OA\Post(
        path: '/api/v1/treasury/pagopa-notices/{id}/cancel',
        operationId: 'cancelTreasuryPagoPaNotice',
        description: 'Cancels a pending notice. A paid, expired or already cancelled notice is refused.',
        summary: 'Cancel pagoPA notice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury pagoPA Notices'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryPagoPaNoticeId')],
        responses: [
            new OA\Response(response: 200, description: 'Notice cancelled', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryPagoPaNoticeResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        $notice = $this->notices->cancel(PagoPaPaymentNotice::query()->findOrFail($id));

        return $this->successResponse(new PagoPaNoticeResource($notice), 'pagoPA notice cancelled');
    }

    /**
     * Records the payment of a pending notice.
     */
    #[OA\Post(
        path: '/api/v1/treasury/pagopa-notices/{id}/mark-paid',
        operationId: 'markPaidTreasuryPagoPaNotice',
        description: 'Marks a pending notice as paid and stores the payment time. A paid, expired or cancelled notice is refused.',
        summary: 'Mark pagoPA notice as paid',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury pagoPA Notices'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryPagoPaNoticeId')],
        responses: [
            new OA\Response(response: 200, description: 'Notice marked as paid', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryPagoPaNoticeResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function markPaid(string $id): JsonResponse
    {
        $notice = $this->notices->markPaid(PagoPaPaymentNotice::query()->findOrFail($id));

        return $this->successResponse(new PagoPaNoticeResource($notice), 'pagoPA notice marked as paid');
    }

    /**
     * The QR code payload of a notice.
     */
    #[OA\Get(
        path: '/api/v1/treasury/pagopa-notices/{id}/qr-payload',
        operationId: 'getTreasuryPagoPaNoticeQrPayload',
        description: 'Returns the string to encode in the QR code of the notice: PAGOPA|002|<noticeCode>|<creditorFiscalCode>|<amountInCents>.',
        summary: 'Get pagoPA notice QR payload',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury pagoPA Notices'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryPagoPaNoticeId')],
        responses: [
            new OA\Response(response: 200, description: 'QR payload', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', properties: [new OA\Property(property: 'payload', type: 'string', example: 'PAGOPA|002|302000000000010012|12345678901|12200')], type: 'object'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
        ]
    )]
    public function qrPayload(string $id): JsonResponse
    {
        $notice = PagoPaPaymentNotice::query()->findOrFail($id);

        return $this->successResponse(['payload' => $this->notices->qrPayload($notice)], 'pagoPA QR payload');
    }
}
