<?php

declare(strict_types=1);

namespace Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Integrations\Exceptions\PaymentGatewayException;
use Modules\Integrations\Services\PaymentLinkService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class PaymentLinkWebhookController extends Controller
{
    #[OA\Post(path: '/api/v1/webhooks/payment-links/{tenant}', operationId: 'paymentLinkWebhook', description: 'Called by the payment provider. The signature is checked with the secret of the tenant before anything is read. Events are processed once.', summary: 'Payment provider webhook', tags: ['Payment Links'], parameters: [new OA\Parameter(name: 'tenant', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Accepted'),
        new OA\Response(response: 400, description: 'Invalid signature'),
        new OA\Response(response: 404, description: 'Unknown tenant'),
    ])]
    public function __invoke(Request $request, string $tenant, TenantContext $tenantContext, PaymentLinkService $links): JsonResponse
    {
        $company = Tenant::findOrFail($tenant);

        try {
            $tenantContext->runWithTenant($company, static fn () => $links->handleWebhook($request));
        } catch (PaymentGatewayException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return response()->json(['received' => true]);
    }
}
