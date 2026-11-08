<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Communication\Http\Requests\SendDocumentRequest;
use Modules\Communication\Http\Requests\UpdateChannelRequest;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Communication\Models\DeliveryLog;
use Modules\Communication\Services\ChannelConfigurator;
use Modules\Communication\Services\DocumentSender;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sending documents, the outbound message log and the channel configuration of the company.
 */
final class CommunicationController extends Controller
{
    use ApiResponse;

    /**
     * Send a document.
     */
    #[OA\Post(
        path: '/api/v1/communication/documents/send',
        operationId: 'sendDocument',
        description: 'Sends an invoice or a quote on a channel and logs the attempt. The status is `sent`, `simulated` (local driver, nothing left the application) or `failed` with HTTP 502. E-mail carries the PDF; the other channels carry a text message. The text comes from the template `document.invoice` / `document.quote` of the company in the requested `locale` when it has one (falling back from `it_CH` to `it` to `en`), otherwise from the standard wording. The recipient defaults to the e-mail or phone of the contact and must have the shape of the channel.',
        summary: 'Send a document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['document_type', 'document_id', 'channel'], properties: [
            new OA\Property(property: 'document_type', type: 'string', enum: ['invoice', 'quote']),
            new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
            new OA\Property(property: 'recipient', type: 'string', nullable: true, example: 'accounting@customer.example'),
            new OA\Property(property: 'message', type: 'string', maxLength: 1000, nullable: true),
            new OA\Property(property: 'locale', type: 'string', nullable: true, example: 'it_CH'),
        ])),
        tags: ['Communication'],
        responses: [
            new OA\Response(response: 201, description: 'Delivery logged (sent or simulated)', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationDeliveryLog')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot send messages', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Document not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid recipient, channel switched off, or no recipient available', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 502, description: 'The provider refused the message or cannot be reached (the attempt is logged as failed)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function send(SendDocumentRequest $request, DocumentSender $sender): JsonResponse
    {
        $data = $request->validated();

        $log = $sender->send($data['document_type'], $data['document_id'], $data['channel'], $data['recipient'] ?? null, $data['message'] ?? null, $request->user()?->id, $data['locale'] ?? null);

        return $log->status === 'failed'
            ? response()->json(['success' => false, 'message' => $log->error, 'data' => $log], Response::HTTP_BAD_GATEWAY)
            : $this->successResponse($log, 'Delivery logged', Response::HTTP_CREATED);
    }

    /**
     * List deliveries.
     */
    #[OA\Get(
        path: '/api/v1/communication/deliveries',
        operationId: 'listDeliveries',
        description: 'Outbound message log of the active tenant, newest first: documents and template messages, with the channel, driver, recipient, subject, status and provider reference. The body of a message is never stored. Filter by document, channel, status, kind of message or period.',
        summary: 'List deliveries',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Communication'],
        parameters: [
            new OA\Parameter(name: 'document_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['sent', 'simulated', 'failed'])),
            new OA\Parameter(name: 'message_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['document', 'message'])),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deliveries', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommunicationDeliveryLog')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot read the communication module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid filter', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function deliveries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_id' => ['nullable', 'uuid'],
            'channel' => ['nullable', 'in:email,whatsapp,telegram,sms'],
            'status' => ['nullable', 'in:sent,simulated,failed'],
            'message_type' => ['nullable', 'in:document,message'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = DeliveryLog::query()
            ->when($validated['document_id'] ?? null, static fn ($query, string $id) => $query->where('document_id', $id))
            ->when($validated['channel'] ?? null, static fn ($query, string $channel) => $query->where('channel', $channel))
            ->when($validated['status'] ?? null, static fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['message_type'] ?? null, static fn ($query, string $type) => $query->where('message_type', $type))
            ->when($validated['from'] ?? null, static fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($validated['to'] ?? null, static fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->successResponse($logs->items(), 'Deliveries retrieved', meta: ['total' => $logs->total(), 'current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage()]);
    }

    /**
     * Show a delivery.
     */
    #[OA\Get(
        path: '/api/v1/communication/deliveries/{id}',
        operationId: 'showDelivery',
        description: 'One entry of the outbound message log.',
        summary: 'Show a delivery',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Communication'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The delivery', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationDeliveryLog')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot read the communication module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function showDelivery(string $id): JsonResponse
    {
        return $this->successResponse(DeliveryLog::query()->findOrFail($id), 'Delivery retrieved');
    }

    /**
     * List channels.
     */
    #[OA\Get(
        path: '/api/v1/communication/channels',
        operationId: 'listCommunicationChannels',
        description: 'Channel configuration of the active tenant, for owners and admins. Secrets (passwords, tokens) are never returned: `settings` holds the other values and `secrets_set` names the secrets that are stored.',
        summary: 'List channels',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Communication'],
        responses: [
            new OA\Response(response: 200, description: 'Channels', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommunicationChannelResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function channels(ChannelConfigurator $configurator): JsonResponse
    {
        return $this->successResponse(CommunicationChannel::query()->get()->map(static fn (CommunicationChannel $channel): array => $configurator->present($channel))->all(), 'Channels retrieved');
    }

    /**
     * Configure a channel.
     */
    #[OA\Put(
        path: '/api/v1/communication/channels/{channel}',
        operationId: 'updateCommunicationChannel',
        description: 'Chooses the driver of a channel and stores its credentials encrypted. Credentials merge: a key that is sent replaces the stored one, `null` removes it, a key left out is kept (so a password is not retyped to change the host). `smtp` (e-mail through the mail server of the company) needs `host` (a public host: private networks, the local machine and cloud metadata are refused), `from_address`, and optionally `port` (default 587), `encryption` (`tls` default, `ssl`, `none`), `username`, `password`, `from_name`. Twilio needs `account_sid`, `auth_token` and `from`; Telegram needs `bot_token`. A driver accepts only its own keys. Every change is written to the audit ledger with the names of the keys, never the values.',
        summary: 'Configure a channel',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationUpdateChannelRequest')),
        tags: ['Communication'],
        parameters: [new OA\Parameter(name: 'channel', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']))],
        responses: [
            new OA\Response(response: 200, description: 'Channel saved', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationChannelResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Unknown driver or credential, non-public SMTP host, missing server or sender', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function updateChannel(UpdateChannelRequest $request, string $channel, ChannelConfigurator $configurator): JsonResponse
    {
        $settings = $configurator->save($channel, $request->validated(), $request->user());

        return $this->successResponse($configurator->present($settings), 'Channel saved');
    }
}
