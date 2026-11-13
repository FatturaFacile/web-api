<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Http\Requests\UpdateNotificationPreferencesRequest;
use Modules\ServiceManagement\Http\Resources\HelpdeskNotificationResource;
use Modules\ServiceManagement\Models\HelpdeskNotification;
use Modules\ServiceManagement\Models\HelpdeskNotificationPreference;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskNotifier;
use OpenApi\Attributes as OA;

/**
 * The caller's helpdesk notifications and notification preferences. Each user sees and changes only their own.
 */
final class HelpdeskNotificationController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/esm/helpdesk/notification-preferences', operationId: 'getEsmHelpdeskNotificationPreferences', description: 'Returns, for every helpdesk event, whether the caller receives it by e-mail and in the in-app inbox. Without a saved choice both are on.', summary: 'Notification preferences', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Notifications'], responses: [
        new OA\Response(response: 200, description: 'Preferences', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskNotificationPreference'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function preferences(Request $request, HelpdeskNotifier $notifier): JsonResponse
    {
        return response()->json(['data' => $notifier->preferencesOf($this->tenantId(), $request->user()->id)]);
    }

    #[OA\Put(path: '/api/v1/esm/helpdesk/notification-preferences', operationId: 'updateEsmHelpdeskNotificationPreferences', description: 'Saves the caller\'s choices for the events given; the other events keep theirs.', summary: 'Change notification preferences', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Notifications'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskUpdateNotificationPreferencesRequest')), responses: [
        new OA\Response(response: 200, description: 'Preferences', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskNotificationPreference'))])),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updatePreferences(UpdateNotificationPreferencesRequest $request, HelpdeskNotifier $notifier): JsonResponse
    {
        $userId = $request->user()->id;

        foreach ($request->validated('preferences') as $preference) {
            HelpdeskNotificationPreference::query()->updateOrCreate(
                ['tenant_id' => $this->tenantId(), 'user_id' => $userId, 'event' => $preference['event']],
                ['mail' => (bool) $preference['mail'], 'in_app' => (bool) $preference['in_app']],
            );
        }

        return response()->json(['data' => $notifier->preferencesOf($this->tenantId(), $userId)]);
    }

    #[OA\Get(path: '/api/v1/esm/helpdesk/notifications', operationId: 'listEsmHelpdeskNotifications', description: 'Lists the caller\'s helpdesk notifications, newest first.', summary: 'Helpdesk notifications', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Notifications'], parameters: [new OA\Parameter(name: 'unread', description: 'Only unread notifications', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')), new OA\Parameter(ref: '#/components/parameters/HelpdeskPage')], responses: [
        new OA\Response(response: 200, description: 'Notifications', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskNotificationResource')), new OA\Property(property: 'meta', properties: [new OA\Property(property: 'unread', type: 'integer')], type: 'object')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function notifications(Request $request): JsonResponse
    {
        $own = HelpdeskNotification::query()->where('tenant_id', $this->tenantId())->where('user_id', $request->user()->id);
        $list = (clone $own)->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))->orderByDesc('created_at')->paginate(RequestedPerPage::resolve(50));

        return response()->json(['data' => HelpdeskNotificationResource::collection($list), 'meta' => ['unread' => (clone $own)->whereNull('read_at')->count()]]);
    }

    #[OA\Post(path: '/api/v1/esm/helpdesk/notifications/{id}/read', operationId: 'readEsmHelpdeskNotification', description: 'Marks one of the caller\'s notifications as read.', summary: 'Mark notification read', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Notifications'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 200, description: 'Notification', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskNotificationResource')])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = HelpdeskNotification::query()->where('tenant_id', $this->tenantId())->where('user_id', $request->user()->id)->findOrFail($id);
        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();

        return response()->json(['data' => new HelpdeskNotificationResource($notification)]);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
