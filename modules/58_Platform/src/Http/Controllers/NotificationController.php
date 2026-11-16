<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Platform\Events\UnreadNotificationsChanged;
use Modules\Platform\Http\Requests\UpdateNotificationPreferencesRequest;
use Modules\Platform\Http\Requests\UpdateNotificationSettingsRequest;
use Modules\Platform\Http\Resources\NotificationResource;
use Modules\Platform\Models\NotificationSetting;
use Modules\Platform\Services\NotificationPreferenceService;
use OpenApi\Attributes as OA;

/**
 * The in-app notification centre of the signed-in user (Laravel database notifications) and their per-channel
 * preferences. Everything is limited to the notifications of the user themselves.
 */
final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    #[OA\Get(path: '/api/v1/platform/notifications', operationId: 'listNotifications', description: 'Notifications of the signed-in user, newest first. Filter: unread=1.', summary: 'List notifications', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], parameters: [
        new OA\Parameter(name: 'unread', in: 'query', schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Notifications with the unread count in meta', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlatformNotification')), new OA\Property(property: 'meta', type: 'object')])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['unread' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $user = $this->user($request);
        $query = ($validated['unread'] ?? false) ? $user->unreadNotifications() : $user->notifications();

        return NotificationResource::collection($query->paginate((int) ($validated['per_page'] ?? 25)))
            ->additional(['unread_count' => $user->unreadNotifications()->count()]);
    }

    #[OA\Post(path: '/api/v1/platform/notifications/{id}/read', operationId: 'markNotificationRead', description: 'Marks one notification of the user as read.', summary: 'Mark a notification as read', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The notification', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlatformNotification')])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlatformNotFound', response: 404),
    ])]
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->user($request)->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();
        event(UnreadNotificationsChanged::for($this->user($request)));

        return response()->json(['data' => (new NotificationResource($notification->refresh()))->resolve()]);
    }

    #[OA\Post(path: '/api/v1/platform/notifications/read-all', operationId: 'markAllNotificationsRead', description: 'Marks every unread notification of the user as read.', summary: 'Mark all notifications as read', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 200, description: 'Number of notifications marked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'marked', type: 'integer')], type: 'object')])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
    ])]
    public function markAllRead(Request $request): JsonResponse
    {
        $unread = $this->user($request)->unreadNotifications();
        $count = $unread->count();
        $unread->update(['read_at' => now()]);
        event(UnreadNotificationsChanged::for($this->user($request)));

        return response()->json(['data' => ['marked' => $count]]);
    }

    #[OA\Delete(path: '/api/v1/platform/notifications/{id}', operationId: 'deleteNotification', description: 'Removes one notification of the user.', summary: 'Delete a notification', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlatformNotFound', response: 404),
    ])]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->user($request)->notifications()->whereKey($id)->firstOrFail()->delete();
        event(UnreadNotificationsChanged::for($this->user($request)));

        return response()->json(null, 204);
    }

    #[OA\Get(path: '/api/v1/platform/notifications/preferences', operationId: 'getNotificationPreferences', description: 'The channels and categories the user can choose from, with their current choice (enabled unless switched off).', summary: 'Get notification preferences', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 200, description: 'Preferences', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlatformNotificationPreferences')])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
    ])]
    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferences->matrix($this->user($request))]);
    }

    #[OA\Put(path: '/api/v1/platform/notifications/preferences', operationId: 'updateNotificationPreferences', description: 'Switches categories on or off per channel. Only the listed combinations change.', summary: 'Update notification preferences', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlatformUpdateNotificationPreferencesRequest')), tags: ['Platform'], responses: [
        new OA\Response(response: 200, description: 'Preferences after the update', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlatformNotificationPreferences')])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlatformUnprocessable', response: 422),
    ])]
    public function updatePreferences(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->preferences->update($user, $request->validated('preferences'));

        return response()->json(['data' => $this->preferences->matrix($user)]);
    }

    #[OA\Get(path: '/api/v1/platform/notifications/realtime', operationId: 'getNotificationRealtime', description: 'Public connection parameters of the WebSocket server for the live unread counter. `enabled` is false when broadcasting is not configured; clients then fall back to polling.', summary: 'Get realtime connection settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 200, description: 'Realtime settings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlatformNotificationRealtime')])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
    ])]
    public function realtime(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $connection = (array) config('broadcasting.connections.reverb');
        $options = (array) ($connection['options'] ?? []);
        $enabled = config('broadcasting.default') === 'reverb' && ! empty($connection['key']) && ! empty($options['host']);

        return response()->json(['data' => [
            'enabled' => $enabled,
            'key' => $enabled ? $connection['key'] : null,
            'host' => $enabled ? $options['host'] : null,
            'port' => $enabled ? (int) ($options['port'] ?? 443) : null,
            'scheme' => $enabled ? ($options['scheme'] ?? 'https') : null,
            'channel' => "users.{$user->id}.notifications",
            'auth_endpoint' => '/api/v1/broadcasting/auth',
        ]]);
    }

    #[OA\Get(path: '/api/v1/platform/notifications/settings', operationId: 'getNotificationSettings', description: 'Do-not-disturb settings of the user: daily quiet hours and snooze.', summary: 'Get notification settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlatformNotificationSettings')])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
    ])]
    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settingsPayload($this->preferences->settings($this->user($request)))]);
    }

    #[OA\Put(path: '/api/v1/platform/notifications/settings', operationId: 'updateNotificationSettings', description: 'Sets quiet hours (daily window in the given timezone, may cross midnight) and an optional snooze. While silenced only in-app notifications are recorded.', summary: 'Update notification settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlatformNotificationSettings')), tags: ['Platform'], responses: [
        new OA\Response(response: 200, description: 'Settings after the update', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlatformNotificationSettings')])),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlatformUnprocessable', response: 422),
    ])]
    public function updateSettings(UpdateNotificationSettingsRequest $request): JsonResponse
    {
        $setting = $this->preferences->updateSettings($this->user($request), $request->validated());

        return response()->json(['data' => $this->settingsPayload($setting)]);
    }

    /**
     * @return array{quiet_hours_enabled: bool, quiet_start: string, quiet_end: string, timezone: string, muted_until: string|null}
     */
    private function settingsPayload(NotificationSetting $setting): array
    {
        return [
            'quiet_hours_enabled' => $setting->quiet_hours_enabled,
            'quiet_start' => $setting->quiet_start,
            'quiet_end' => $setting->quiet_end,
            'timezone' => $setting->timezone,
            'muted_until' => $setting->muted_until?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
