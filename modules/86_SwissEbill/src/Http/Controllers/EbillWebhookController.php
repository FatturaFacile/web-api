<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\SwissEbill\Application\Commands\ProcessEbillWebhookCommand;
use Modules\SwissEbill\Application\Commands\ProcessEbillWebhookHandler;
use Modules\SwissEbill\Domain\Exceptions\InvalidTransitionException;
use Modules\SwissEbill\Http\Requests\EbillWebhookRequest;
use OpenApi\Attributes as OA;

/**
 * Inbound notifications of SIX Paynet / PostFinance (delivery receipts, debtor approvals and rejections). Public route: the signature
 * of the call, verified with the secret of the company that owns the slug, is the credential.
 */
final class EbillWebhookController extends Controller
{
    use ApiResponse;

    #[OA\Post(path: '/api/v1/webhooks/swiss-ebill/{tenantSlug}', operationId: 'swissEbillWebhook', summary: 'Receive the status notifications of the Swiss eBill gateway (HMAC-SHA256, JWS or mutual TLS)', tags: ['Swiss eBill'], parameters: [new OA\Parameter(name: 'tenantSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Notifications processed (a replayed event is acknowledged as duplicate)'), new OA\Response(response: 401, description: 'Invalid signature')])]
    public function __invoke(string $tenantSlug, EbillWebhookRequest $request, ProcessEbillWebhookHandler $handler): JsonResponse
    {
        try {
            $results = $handler->handle(new ProcessEbillWebhookCommand($tenantSlug, $request, $request->json()->all() ?: $request->all()));
        } catch (InvalidTransitionException $e) {
            // The notification does not fit the state yet (it overtook the receipt): the gateway should deliver it again.
            return response()->json(['type' => 'https://httpstatuses.com/409', 'title' => $e->errorCode(), 'status' => 409, 'detail' => $e->getMessage(), 'code' => $e->errorCode()], 409, ['Content-Type' => 'application/problem+json']);
        }

        return $this->successResponse(['events' => $results], __('swiss_ebill.messages.webhook_processed'));
    }
}
