<?php

declare(strict_types=1);

namespace Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Integrations\Http\Resources\WebhookDeliveryResource;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Integrations\Webhooks\WebhookEndpointManager;
use OpenApi\Attributes as OA;

/**
 * The delivery log of the active tenant: what was sent to which endpoint, the answer, the duration, and the manual
 * redelivery of the deliveries that failed for good.
 */
final class WebhookDeliveryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WebhookEndpointManager $endpoints) {}

    #[OA\Get(path: '/api/v1/integrations/webhook-deliveries', operationId: 'listWebhookDeliveries', summary: 'Webhook delivery log', description: 'Paginated, newest first. Filter by endpoint, status ("dead" lists the dead letters) or event type.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], parameters: [
        new OA\Parameter(name: 'endpoint_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'retrying', 'succeeded', 'dead'])),
        new OA\Parameter(name: 'event_name', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'A page of deliveries', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Invalid filter', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'endpoint_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in([WebhookDelivery::StatusPending, WebhookDelivery::StatusRetrying, WebhookDelivery::StatusSucceeded, WebhookDelivery::StatusDead])],
            'event_name' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $deliveries = WebhookDelivery::query()
            ->when($filters['endpoint_id'] ?? null, fn ($query, $id) => $query->where('webhook_endpoint_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['event_name'] ?? null, fn ($query, $name) => $query->where('event_name', $name))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json(WebhookDeliveryResource::collection($deliveries)->response()->getData(true));
    }

    #[OA\Get(path: '/api/v1/integrations/webhook-deliveries/{id}', operationId: 'showWebhookDelivery', summary: 'Show webhook delivery', description: 'The delivery with the payload that was sent and the log of its attempts (status code, duration, error, start of the answer).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The delivery', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new WebhookDeliveryResource(WebhookDelivery::with('attemptLog')->findOrFail($id), true), 'Webhook delivery retrieved');
    }

    #[OA\Post(path: '/api/v1/integrations/webhook-deliveries/{id}/redeliver', operationId: 'redeliverWebhookDelivery', summary: 'Redeliver a webhook delivery', description: 'Sends a dead (or already succeeded) delivery again from its first attempt, with the same idempotency key (X-FF-Webhook-Id). Refused (422) while the delivery is still being retried and when its endpoint is disabled.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Integrations'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 202, description: 'Delivery queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Still in progress or endpoint disabled', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function redeliver(string $id): JsonResponse
    {
        $delivery = $this->endpoints->redeliver(WebhookDelivery::findOrFail($id));

        return $this->successResponse(new WebhookDeliveryResource($delivery), 'Webhook delivery queued', 202);
    }
}
