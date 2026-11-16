<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Http\Requests\RecipientRules;
use Modules\Communication\Http\Requests\SendMessageRequest;
use Modules\Communication\Services\MessageDispatcher;
use Modules\Communication\Services\TemplateRenderer;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Messages built from the templates of the company, and the test message of a channel.
 */
final class MessageController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly TemplateRenderer $renderer, private readonly MessageDispatcher $dispatcher) {}

    /**
     * Send a message.
     */
    #[OA\Post(
        path: '/api/v1/communication/messages/send',
        operationId: 'sendCommunicationMessage',
        description: 'Sends a message built from the template `template_code` of the company on a channel, in the requested `locale` (falling back from `it_CH` to `it` to `en`), and logs the attempt with the template and language used. A placeholder without a variable is refused. The status is `sent`, `simulated` or `failed` with HTTP 502. The body is not stored in the log.',
        summary: 'Send a template message',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationSendMessageRequest')),
        tags: ['Communication'],
        responses: [
            new OA\Response(response: 201, description: 'Delivery logged (sent or simulated)', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationDeliveryLog')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot send messages', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'No active template for that code and channel', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid recipient, channel switched off or a placeholder without a variable', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 502, description: 'The provider refused the message or cannot be reached (the attempt is logged as failed)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function send(SendMessageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $settings = $this->dispatcher->settings($data['channel']);
        $template = $this->renderer->resolve($data['template_code'], $data['channel'], $data['locale'] ?? null);

        abort_if($template === null, Response::HTTP_NOT_FOUND, __('communication_errors.template.not_found'));

        $rendered = $this->renderer->render($template, $data['variables'] ?? []);
        $subject = $rendered['subject'] ?? $data['template_code'];

        $log = $this->dispatcher->dispatch(
            $data['channel'],
            new DeliveryMessage($data['channel'], $data['recipient'], $subject, $rendered['body']),
            ['message_type' => 'message', 'sent_by' => $request->user()?->id, 'template_code' => $data['template_code'], 'locale' => $template->locale],
            $settings
        );

        return $this->respond($log);
    }

    /**
     * Test a channel.
     */
    #[OA\Post(
        path: '/api/v1/communication/channels/{channel}/test',
        operationId: 'testCommunicationChannel',
        description: 'Sends a short test message through the channel as configured, to check the server and the login (for SMTP: host, TLS and credentials). The attempt is logged like any other; a failure answers 502 with the reason from the provider.',
        summary: 'Test a channel',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['recipient'], properties: [new OA\Property(property: 'recipient', type: 'string', example: 'it@company.example')])),
        tags: ['Communication'],
        parameters: [new OA\Parameter(name: 'channel', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']))],
        responses: [
            new OA\Response(response: 201, description: 'Test logged (sent or simulated)', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationDeliveryLog')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid recipient or channel switched off', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 502, description: 'The provider refused the message or cannot be reached', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function testChannel(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate(['recipient' => ['required', ...RecipientRules::for($channel)]]);
        $settings = $this->dispatcher->settings($channel);

        if ($settings === null && $channel !== 'email') {
            throw ValidationException::withMessages(['channel' => __('communication_errors.channel.not_configured')]);
        }

        $log = $this->dispatcher->dispatch(
            $channel,
            new DeliveryMessage($channel, $data['recipient'], 'Test message', 'This is a test message sent from your ERP to check the channel configuration.'),
            ['message_type' => 'message', 'sent_by' => $request->user()?->id, 'template_code' => 'channel.test'],
            $settings
        );

        return $this->respond($log);
    }

    private function respond(mixed $log): JsonResponse
    {
        return $log->status === 'failed'
            ? response()->json(['success' => false, 'message' => $log->error, 'data' => $log], Response::HTTP_BAD_GATEWAY)
            : $this->successResponse($log, 'Delivery logged', Response::HTTP_CREATED);
    }
}
