<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Communication\Models\DeliveryLog;
use Modules\Communication\Services\DocumentSender;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class CommunicationController extends Controller
{
    use ApiResponse;

    #[OA\Post(path: '/api/v1/communication/documents/send', operationId: 'sendDocument', description: 'Sends an invoice or a quote on a channel and logs the attempt. The status is `sent`, `simulated` (local driver, nothing left the application) or `failed` with HTTP 502. E-mail carries the PDF; the other channels carry a text message.', summary: 'Send a document', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['document_type', 'document_id', 'channel'], properties: [
        new OA\Property(property: 'document_type', type: 'string', enum: ['invoice', 'quote']),
        new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
        new OA\Property(property: 'recipient', type: 'string', nullable: true),
        new OA\Property(property: 'message', type: 'string', nullable: true),
    ])), tags: ['Communication'], responses: [
        new OA\Response(response: 201, description: 'Delivery logged', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Document not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'No recipient, or channel disabled', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        new OA\Response(response: 502, description: 'The provider refused the message', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function send(Request $request, DocumentSender $sender): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'in:invoice,quote'],
            'document_id' => ['required', 'uuid'],
            'channel' => ['required', 'in:email,whatsapp,telegram,sms'],
            'recipient' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $log = $sender->send($data['document_type'], $data['document_id'], $data['channel'], $data['recipient'] ?? null, $data['message'] ?? null, $request->user()?->id);

        return $log->status === 'failed'
            ? response()->json(['success' => false, 'message' => $log->error, 'data' => $log], Response::HTTP_BAD_GATEWAY)
            : $this->successResponse($log, 'Delivery logged', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/communication/deliveries', operationId: 'listDeliveries', description: 'Delivery log of the active tenant, newest first. Filter by `document_id`.', summary: 'List deliveries', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication'], parameters: [new OA\Parameter(name: 'document_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The log', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function deliveries(Request $request): JsonResponse
    {
        $validated = $request->validate(['document_id' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $logs = DeliveryLog::query()
            ->when($validated['document_id'] ?? null, static fn ($query, string $id) => $query->where('document_id', $id))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->successResponse($logs->items(), 'Deliveries retrieved', meta: ['total' => $logs->total(), 'current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage()]);
    }

    #[OA\Get(path: '/api/v1/communication/channels', operationId: 'listCommunicationChannels', description: 'Channel configuration of the active tenant, for owners and admins. Credentials are never returned.', summary: 'List channels', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication'], responses: [
        new OA\Response(response: 200, description: 'The channels', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function channels(): JsonResponse
    {
        return $this->successResponse(CommunicationChannel::query()->get()->map(static fn (CommunicationChannel $channel): array => [
            ...$channel->toArray(),
            'has_credentials' => ! empty($channel->credentials),
        ])->all(), 'Channels retrieved');
    }

    #[OA\Put(path: '/api/v1/communication/channels/{channel}', operationId: 'updateCommunicationChannel', description: 'Chooses the driver of a channel and stores its credentials encrypted. Twilio needs `account_sid`, `auth_token` and `from`; Telegram needs `bot_token`.', summary: 'Configure a channel', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['driver'], properties: [
        new OA\Property(property: 'driver', type: 'string', enum: ['local', 'mail', 'telegram', 'twilio']),
        new OA\Property(property: 'is_enabled', type: 'boolean'),
        new OA\Property(property: 'credentials', type: 'object', nullable: true),
    ])), tags: ['Communication'], parameters: [new OA\Parameter(name: 'channel', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']))], responses: [
        new OA\Response(response: 200, description: 'Channel saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateChannel(Request $request, string $channel, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'driver' => ['required', Rule::in(array_keys(DocumentSender::driversOf($channel)))],
            'is_enabled' => ['nullable', 'boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['string', 'max:500'],
        ]);

        $settings = CommunicationChannel::firstOrNew(['tenant_id' => $tenantContext->getTenantId(), 'channel' => $channel]);
        $settings->fill(['driver' => $data['driver'], 'is_enabled' => $data['is_enabled'] ?? true]);

        if (array_key_exists('credentials', $data)) {
            $settings->credentials = $data['credentials'];
        }

        $settings->save();

        return $this->successResponse($settings, 'Channel saved');
    }
}
