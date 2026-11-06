<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SDI\Jobs\PollSdiChannelJob;
use Modules\SDI\Services\SdiGatewayManager;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class SdiGatewayWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SdiGatewayManager $gateways) {}

    /**
     * Wake up the read of the channel when an API provider signals news. The payload itself is not trusted or parsed.
     */
    #[OA\Post(
        path: '/api/v1/webhooks/sdi/gateways/{tenant}',
        operationId: 'sdiGatewayWebhook',
        description: 'Endpoint to register on the API provider. A verified call queues a read of the channel of the tenant. Openapi sends the tenant secret in the token query parameter, A-Cube as a bearer token, Invoicetronic signs the body in the Invoicetronic-Signature header.',
        summary: 'Receive SDI provider webhook',
        tags: ['Webhooks', 'SDI'],
        parameters: [
            new OA\Parameter(name: 'tenant', description: 'Tenant UUID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'token', description: 'Tenant webhook secret, for providers that cannot sign', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 202, description: 'Read queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Invalid webhook credentials', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function __invoke(Request $request, string $tenant): JsonResponse
    {
        $model = Tenant::findOrFail($tenant);
        $channel = $this->gateways->channelFor($model);

        abort_if($channel === null || ! $channel->isApiProvider(), Response::HTTP_NOT_FOUND);
        abort_unless($this->gateways->for($model)->verifyWebhook($model, $request), Response::HTTP_UNAUTHORIZED);

        PollSdiChannelJob::dispatch($model->id);

        return $this->successResponse(null, __('sdi.messages.poll_queued'), Response::HTTP_ACCEPTED);
    }
}
