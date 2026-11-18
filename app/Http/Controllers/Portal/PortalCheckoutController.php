<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Models\PortalAccessToken;
use App\Services\Portal\PortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Integrations\Models\PaymentLink;
use Modules\Integrations\Services\PaymentLinkService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Online payment of an open invoice from the portal. The hosted payment page is the one of the payment-link gateway of
 * the tenant; the provider's signed webhook (payment-links route) books the payment and closes the invoice.
 */
class PortalCheckoutController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentLinkService $links,
        private readonly PortalTokenService $tokens,
    ) {}

    #[OA\Post(
        path: '/api/v1/portal/invoices/{id}/checkout',
        operationId: 'createPortalInvoiceCheckout',
        description: 'Opens a hosted payment page for the open balance of the invoice. An already open page is returned again instead of creating a second one.',
        summary: 'Start an invoice payment',
        security: [['portalBearer' => []]],
        tags: ['Customer Portal'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Payment page', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Nothing to pay online', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var PortalAccessToken $session */
        $session = $request->attributes->get('portal_token');

        $invoice = Invoice::where('contact_id', $session->contact_id)->findOrFail($id);

        $link = PaymentLink::where('invoice_id', $invoice->id)->where('status', 'open')->latest()->first() ?? $this->links->create($invoice);

        $this->tokens->logActivity($session, 'checkout_started', 'invoice', $invoice->id, ['provider' => $link->provider]);

        return $this->successResponse(
            data: [
                'invoice_id' => $invoice->id,
                'provider' => $link->provider,
                'amount' => (string) $link->amount,
                'currency' => $link->currency,
                'url' => $link->url,
            ],
            message: __('portal.messages.checkout_created'),
            statusCode: Response::HTTP_CREATED,
        );
    }
}
