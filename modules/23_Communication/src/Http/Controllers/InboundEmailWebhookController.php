<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Communication\Services\InboxService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public webhook of an inbound email provider: the email channel of a company is found by its UUID and the message is
 * recorded in the inbox when the HMAC-SHA256 signature of `timestamp.body`, made with the inbound secret of the channel,
 * matches and the timestamp is recent.
 */
final class InboundEmailWebhookController extends Controller
{
    /**
     * Seconds a signed delivery stays valid.
     */
    public const int ToleranceSeconds = 300;

    public function __construct(private readonly InboxService $inbox, private readonly TenantContext $tenantContext) {}

    #[OA\Post(path: '/api/v1/webhooks/communication/email/{channel}', operationId: 'receiveCommunicationInboundEmail', description: 'Public webhook of an inbound email provider. Headers: X-Timestamp (unix seconds, at most 5 minutes old) and X-Signature (hex HMAC-SHA256 of "timestamp.rawBody" with the inbound secret of the email channel). The message joins the open conversation of the sender or opens a new one.', summary: 'Receive an inbound email', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationInboundEmailWebhookRequest')), tags: ['Communication Inbox'], parameters: [
        new OA\Parameter(name: 'channel', in: 'path', required: true, description: 'Id of the email channel of the company', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 201, description: 'Message recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationConversationMessage')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function __invoke(Request $request, string $channel): JsonResponse
    {
        $settings = Str::isUuid($channel)
            ? CommunicationChannel::withoutGlobalScopes()->where('channel', 'email')->where('is_enabled', true)->find($channel)
            : null;
        $secret = (string) ($settings?->credentials['inbound_secret'] ?? '');

        if ($settings === null || $secret === '') {
            return response()->json(['message' => __('communication_errors.webhook.unknown_channel')], Response::HTTP_NOT_FOUND);
        }

        $timestamp = (string) $request->header('X-Timestamp', '');
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > self::ToleranceSeconds
            || ! hash_equals($expected, strtolower((string) $request->header('X-Signature', '')))) {
            return response()->json(['message' => __('communication_errors.webhook.invalid_signature')], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'from' => ['required', 'email:rfc', 'max:190'],
            'from_name' => ['nullable', 'string', 'max:160'],
            'subject' => ['nullable', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:20000'],
            'received_at' => ['nullable', 'date'],
        ]);

        $tenant = Tenant::query()->findOrFail($settings->tenant_id);
        $message = $this->tenantContext->runWithTenant($tenant, fn () => $this->inbox->receive((string) $tenant->id, [
            'channel' => 'email',
            'address' => $data['from'],
            'name' => $data['from_name'] ?? null,
            'subject' => $data['subject'] ?? null,
            'body' => $data['text'],
            'received_at' => $data['received_at'] ?? null,
        ]));

        return response()->json(['data' => ['id' => $message->id, 'conversation_id' => $message->conversation_id]], Response::HTTP_CREATED);
    }
}
