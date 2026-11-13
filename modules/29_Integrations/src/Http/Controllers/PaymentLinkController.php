<?php

declare(strict_types=1);

namespace Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Integrations\Models\PaymentGatewaySetting;
use Modules\Integrations\Models\PaymentLink;
use Modules\Integrations\Services\PaymentGatewayResolver;
use Modules\Integrations\Services\PaymentLinkService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class PaymentLinkController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/payment-links', operationId: 'listPaymentLinks', description: 'Payment links of the active tenant, optionally for an invoice.', summary: 'List payment links', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Payment Links'], parameters: [new OA\Parameter(name: 'invoice_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 200))], responses: [
        new OA\Response(response: 200, description: 'The links', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['invoice_id' => ['nullable', 'uuid']]);

        $links = PaymentLink::query()->when($validated['invoice_id'] ?? null, static fn ($query, string $id) => $query->where('invoice_id', $id))->latest()->gridPaginate(RequestedPerPage::resolve(25));

        return $this->successResponse($links->items(), 'Payment links retrieved', meta: ['current_page' => $links->currentPage(), 'last_page' => $links->lastPage(), 'per_page' => $links->perPage(), 'total' => $links->total()]);
    }

    #[OA\Post(path: '/api/v1/payment-links', operationId: 'createPaymentLink', description: 'Creates a hosted payment page for the open balance of an invoice. Card data never reaches this application.', summary: 'Create payment link', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['invoice_id'], properties: [new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid')])), tags: ['Payment Links'], responses: [
        new OA\Response(response: 201, description: 'Link created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Invoice not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Nothing to collect, a link is already open for the invoice, or refused by the provider', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(Request $request, PaymentLinkService $links): JsonResponse
    {
        $validated = $request->validate(['invoice_id' => ['required', 'uuid']]);

        return $this->successResponse($links->create(Invoice::findOrFail($validated['invoice_id'])), 'Payment link created', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/payment-links/{id}', operationId: 'getPaymentLink', description: 'One payment link of the active tenant, with its status (`open`, `paid`, `review` when the payment needs a person, `cancelled`, `failed`).', summary: 'Get payment link', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Payment Links'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The link', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(PaymentLink::findOrFail($id), 'Payment link retrieved');
    }

    #[OA\Post(path: '/api/v1/payment-links/{id}/cancel', operationId: 'cancelPaymentLink', description: 'Withdraws an open link so that a new one can be created for the invoice (only one link per invoice can be open). The page stays alive at the provider until it expires: a payment that still arrives is not booked, the link goes to `review`.', summary: 'Cancel payment link', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Payment Links'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Link cancelled', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'The link is not open', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function cancel(string $id, PaymentLinkService $links): JsonResponse
    {
        return $this->successResponse($links->cancel(PaymentLink::findOrFail($id)), 'Payment link cancelled');
    }

    #[OA\Get(path: '/api/v1/integrations/payment-gateway', operationId: 'getPaymentGateway', description: 'Payment provider of the active tenant, for owners and admins. Credentials are never returned.', summary: 'Get payment gateway', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Payment Links'], responses: [
        new OA\Response(response: 200, description: 'The settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function settings(TenantContext $tenantContext): JsonResponse
    {
        $settings = PaymentGatewaySetting::where('tenant_id', $tenantContext->getTenantId())->first();

        return $this->successResponse(['provider' => $settings->provider ?? 'local', 'has_credentials' => ! empty($settings?->credentials), 'webhook_url' => route('payment-links.webhook', ['tenant' => $tenantContext->getTenantId()])], 'Payment gateway retrieved');
    }

    #[OA\Put(path: '/api/v1/integrations/payment-gateway', operationId: 'updatePaymentGateway', description: 'Chooses the provider and stores its credentials encrypted. Stripe needs `secret_key` and `webhook_secret`; the local driver needs `webhook_secret`.', summary: 'Configure payment gateway', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['provider'], properties: [
        new OA\Property(property: 'provider', type: 'string', enum: ['local', 'stripe']),
        new OA\Property(property: 'credentials', type: 'object', nullable: true),
    ])), tags: ['Payment Links'], responses: [
        new OA\Response(response: 200, description: 'Gateway saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateSettings(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(PaymentGatewayResolver::providers())],
            'credentials' => ['nullable', 'array'],
            'credentials.secret_key' => ['nullable', 'string', 'max:255'],
            'credentials.webhook_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = PaymentGatewaySetting::firstOrNew(['tenant_id' => $tenantContext->getTenantId()]);
        $settings->provider = $data['provider'];

        if (array_key_exists('credentials', $data)) {
            $settings->credentials = $data['credentials'];
        }

        $settings->save();

        return $this->successResponse(['provider' => $settings->provider, 'has_credentials' => ! empty($settings->credentials)], 'Payment gateway saved');
    }
}
