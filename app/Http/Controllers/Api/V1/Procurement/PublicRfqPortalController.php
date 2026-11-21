<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Procurement;

use App\Application\Procurement\Rfq\Commands\DeclineRfqInvitationCommand;
use App\Application\Procurement\Rfq\Commands\SubmitExternalSupplierBidCommand;
use App\Application\Procurement\Rfq\Handlers\DeclineRfqInvitationHandler;
use App\Application\Procurement\Rfq\Handlers\GetExternalRfqPortalHandler;
use App\Application\Procurement\Rfq\Handlers\SubmitExternalSupplierBidHandler;
use App\Application\Procurement\Rfq\Queries\GetExternalRfqPortalQuery;
use App\Domain\Procurement\Rfq\Models\RfqAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\Rfq\DeclineRfqInvitationRequest;
use App\Http\Requests\Procurement\Rfq\SubmitExternalBidRequest;
use App\Http\Resources\Procurement\Rfq\ExternalRfqPortalResource;
use App\Http\Resources\Procurement\Rfq\RfqInvitationResource;
use App\Http\Resources\Procurement\Rfq\SupplierBidResource;
use App\Infrastructure\Procurement\Rfq\Services\RfqAttachmentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, magic-link token-authenticated controller for the External Supplier Bidding Portal (Mod 045).
 */
#[OA\Tag(name: 'Public RFQ Supplier Portal', description: 'External zero-registration supplier bidding endpoints')]
final class PublicRfqPortalController extends Controller
{
    public function __construct(
        private readonly GetExternalRfqPortalHandler $portalQueryHandler,
        private readonly SubmitExternalSupplierBidHandler $submitBidHandler,
        private readonly DeclineRfqInvitationHandler $declineHandler,
        private readonly RfqAttachmentStorageService $attachmentStorage,
    ) {}

    #[OA\Get(
        path: '/api/v1/public/rfq-portal/{token}',
        operationId: 'publicRfqPortalShow',
        summary: 'Get supplier-scoped RFQ bidding view',
        tags: ['Public RFQ Supplier Portal'],
        responses: [
            new OA\Response(response: 200, description: 'Supplier portal RFQ view'),
            new OA\Response(response: 401, description: 'Invalid token'),
            new OA\Response(response: 410, description: 'Expired or cancelled'),
        ]
    )]
    public function show(Request $request, string $token): ExternalRfqPortalResource
    {
        $invitationId = (string) $request->attributes->get('rfq_invitation_id');
        $locale = $request->query('locale');

        $payload = $this->portalQueryHandler->handle(new GetExternalRfqPortalQuery(
            invitationId: $invitationId,
            requestedLocale: is_string($locale) && $locale !== '' ? $locale : null,
        ));

        return new ExternalRfqPortalResource($payload);
    }

    #[OA\Post(
        path: '/api/v1/public/rfq-portal/{token}/bid',
        operationId: 'publicRfqPortalSubmitBid',
        summary: 'Save draft or submit/revise supplier bid',
        tags: ['Public RFQ Supplier Portal'],
        responses: [
            new OA\Response(response: 200, description: 'Bid persisted'),
            new OA\Response(response: 401, description: 'Invalid token'),
            new OA\Response(response: 410, description: 'Expired or cancelled'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function submitBid(SubmitExternalBidRequest $request, string $token): JsonResponse
    {
        $invitationId = (string) $request->attributes->get('rfq_invitation_id');
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $lines = [];
        foreach ((array) $data['lines'] as $line) {
            /** @var array<string, mixed> $line */
            $lines[] = [
                'rfq_line_id' => (string) $line['rfq_line_id'],
                'is_no_bid' => (bool) ($line['is_no_bid'] ?? false),
                'quoted_quantity' => $line['quoted_quantity'] ?? null,
                'unit_price' => $line['unit_price'] ?? null,
                'discount_percent' => $line['discount_percent'] ?? 0,
                'lead_time_days' => isset($line['lead_time_days']) ? (int) $line['lead_time_days'] : 0,
                'promised_delivery_date' => $line['promised_delivery_date'] ?? null,
                'supplier_notes' => $line['supplier_notes'] ?? null,
                'tax_rate' => $line['tax_rate'] ?? '22.00',
            ];
        }

        $bid = $this->submitBidHandler->handle(new SubmitExternalSupplierBidCommand(
            invitationId: $invitationId,
            action: (string) ($data['action'] ?? 'submit'),
            supplierQuoteReference: $data['supplier_quote_reference'] ?? null,
            currencyCode: (string) ($data['currency_code'] ?? $data['currency'] ?? ''),
            exchangeRateToRfq: isset($data['exchange_rate_to_rfq']) ? (string) $data['exchange_rate_to_rfq'] : null,
            paymentTermsDays: (int) ($data['payment_terms_days'] ?? 30),
            incoterms: $data['incoterms'] ?? null,
            shippingCost: $data['shipping_cost'] ?? 0,
            validUntil: $data['valid_until'] ?? null,
            generalNotes: $data['general_notes'] ?? null,
            lines: $lines,
            quoteAttachment: $request->file('quote_attachment'),
            ipAddress: $request->ip() ?? '127.0.0.1',
            userAgent: (string) ($request->userAgent() ?? 'unknown'),
        ));

        return (new SupplierBidResource($bid))->response()->setStatusCode(200);
    }

    #[OA\Post(
        path: '/api/v1/public/rfq-portal/{token}/decline',
        operationId: 'publicRfqPortalDecline',
        summary: 'Decline RFQ invitation',
        tags: ['Public RFQ Supplier Portal'],
        responses: [
            new OA\Response(response: 200, description: 'Invitation declined'),
            new OA\Response(response: 401, description: 'Invalid token'),
            new OA\Response(response: 410, description: 'Expired or cancelled'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function decline(DeclineRfqInvitationRequest $request, string $token): RfqInvitationResource
    {
        $invitationId = (string) $request->attributes->get('rfq_invitation_id');

        $invitation = $this->declineHandler->handle(new DeclineRfqInvitationCommand(
            invitationId: $invitationId,
            reason: $request->validated('reason'),
        ));

        return new RfqInvitationResource($invitation);
    }

    #[OA\Get(
        path: '/api/v1/public/rfq-portal/{token}/attachments/{attachment}',
        operationId: 'publicRfqPortalDownloadAttachment',
        summary: 'Download supplier-visible technical attachment',
        tags: ['Public RFQ Supplier Portal'],
        responses: [
            new OA\Response(response: 200, description: 'File stream'),
            new OA\Response(response: 404, description: 'Attachment not visible or not found'),
        ]
    )]
    public function downloadAttachment(Request $request, string $token, string $attachment): StreamedResponse
    {
        $rfqId = (string) $request->attributes->get('rfq_id');

        $record = RfqAttachment::query()
            ->where('rfq_id', $rfqId)
            ->where('is_visible_to_suppliers', true)
            ->findOrFail($attachment);

        return $this->attachmentStorage->streamRfqAttachment($record);
    }
}
