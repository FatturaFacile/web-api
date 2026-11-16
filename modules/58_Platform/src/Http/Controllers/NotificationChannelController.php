<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Platform\Models\NotificationDelivery;
use Modules\Platform\Models\NotificationDevice;
use Modules\Platform\Models\NotificationSetting;
use OpenApi\Attributes as OA;

/**
 * The delivery channels of the signed-in user: mobile devices (push), the Telegram link and the delivery register.
 */
final class NotificationChannelController extends Controller
{
    #[OA\Get(path: '/api/v1/platform/notifications/devices', operationId: 'getNotificationDevices', description: 'Mobile devices registered for push notifications.', summary: 'List notification devices', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 200, description: 'Devices'),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
    ])]
    public function devices(Request $request): JsonResponse
    {
        return response()->json(['data' => NotificationDevice::query()->where('user_id', $this->user($request)->id)->orderByDesc('last_seen_at')->get(['id', 'platform', 'name', 'last_seen_at'])]);
    }

    #[OA\Post(path: '/api/v1/platform/notifications/devices', operationId: 'registerNotificationDevice', description: 'Registers (or refreshes) the FCM token of a mobile device. A token already known for another user moves to the caller.', summary: 'Register a device', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 201, description: 'Registered'),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlatformUnprocessable', response: 422),
    ])]
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['platform' => ['required', 'in:ios,android,web'], 'token' => ['required', 'string', 'max:500'], 'name' => ['nullable', 'string', 'max:120']]);
        $device = NotificationDevice::query()->updateOrCreate(['token' => $data['token']], ['user_id' => $this->user($request)->id, 'platform' => $data['platform'], 'name' => $data['name'] ?? null, 'last_seen_at' => now()]);

        return response()->json(['data' => ['id' => $device->id]], 201);
    }

    #[OA\Delete(path: '/api/v1/platform/notifications/devices/{id}', operationId: 'deleteNotificationDevice', description: 'Removes a device (for example on sign-out).', summary: 'Remove a device', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Removed'),
        new OA\Response(ref: '#/components/responses/PlatformNotFound', response: 404),
    ])]
    public function destroyDevice(Request $request, string $id): JsonResponse
    {
        NotificationDevice::query()->where('user_id', $this->user($request)->id)->whereKey($id)->firstOrFail()->delete();

        return response()->json(null, 204);
    }

    #[OA\Get(path: '/api/v1/platform/notifications/deliveries', operationId: 'getNotificationDeliveries', description: 'Delivery register of the user: per channel whether the notification was queued, sent, opened or failed. Filter with status and channel.', summary: 'Delivery register', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['queued', 'sent', 'opened', 'failed'])),
        new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ], responses: [
        new OA\Response(response: 200, description: 'Deliveries'),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
    ])]
    public function deliveries(Request $request): JsonResponse
    {
        $query = NotificationDelivery::query()->where('user_id', $this->user($request)->id)->latest()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('channel'), fn ($q, $channel) => $q->where('channel', $channel));
        $page = $query->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    #[OA\Post(path: '/api/v1/platform/notifications/channels/telegram/link', operationId: 'linkTelegram', description: 'Starts linking the Telegram bot: returns the deep link to open in Telegram; pressing Start connects the chat.', summary: 'Link Telegram', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 200, description: 'Link to open'),
        new OA\Response(response: 409, description: 'Telegram bot not configured'),
    ])]
    public function linkTelegram(Request $request): JsonResponse
    {
        $bot = config('platform.telegram.bot_username');

        if (! is_string($bot) || $bot === '') {
            return response()->json(['message' => __('Telegram bot not configured')], 409);
        }

        $token = Str::random(40);
        NotificationSetting::query()->updateOrCreate(['user_id' => $this->user($request)->id], ['telegram_link_token' => $token]);

        return response()->json(['data' => ['url' => "https://t.me/{$bot}?start={$token}"]]);
    }

    #[OA\Delete(path: '/api/v1/platform/notifications/channels/telegram', operationId: 'unlinkTelegram', description: 'Disconnects the Telegram chat.', summary: 'Unlink Telegram', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 204, description: 'Unlinked'),
    ])]
    public function unlinkTelegram(Request $request): JsonResponse
    {
        NotificationSetting::query()->where('user_id', $this->user($request)->id)->update(['telegram_chat_id' => null, 'telegram_link_token' => null]);

        return response()->json(null, 204);
    }

    /**
     * Telegram bot webhook (public; the secret in the URL authenticates Telegram). `/start <token>` links the chat of
     * the sender to the user who generated the token.
     */
    #[OA\Post(path: '/api/v1/platform/telegram/webhook/{secret}', operationId: 'telegramWebhook', description: 'Bot webhook of Telegram; links a chat when the user presses Start on the link from linkTelegram.', summary: 'Telegram webhook', tags: ['Platform'], parameters: [new OA\Parameter(name: 'secret', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Handled'),
        new OA\Response(response: 404, description: 'Unknown secret'),
    ])]
    public function telegramWebhook(Request $request, string $secret): JsonResponse
    {
        $expected = config('platform.telegram.webhook_secret');

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $secret)) {
            abort(404);
        }

        $text = (string) $request->input('message.text', '');
        $chatId = $request->input('message.chat.id');

        if ($chatId !== null && preg_match('/^\/start\s+(\S{20,64})$/', $text, $m) === 1) {
            NotificationSetting::query()->where('telegram_link_token', $m[1])->update(['telegram_chat_id' => (string) $chatId, 'telegram_link_token' => null]);
        }

        return response()->json(['ok' => true]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
