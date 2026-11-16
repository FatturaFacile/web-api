<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Platform\Models\NotificationAlertRule;
use OpenApi\Attributes as OA;

/**
 * Administrator-defined alert rules (for example: notify when a customer's overdue total exceeds 10,000 CHF).
 */
final class NotificationAlertRuleController extends Controller
{
    #[OA\Get(path: '/api/v1/platform/notification-alert-rules', operationId: 'getNotificationAlertRules', description: 'Alert rules of the tenant and the supported metrics.', summary: 'List alert rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 200, description: 'Rules'),
        new OA\Response(ref: '#/components/responses/PlatformUnauthenticated', response: 401),
    ])]
    public function index(): JsonResponse
    {
        return response()->json(['data' => NotificationAlertRule::query()->orderBy('name')->get(), 'metrics' => NotificationAlertRule::METRICS]);
    }

    #[OA\Post(path: '/api/v1/platform/notification-alert-rules', operationId: 'createNotificationAlertRule', description: 'Creates an alert rule. Recipients default to the tenant owners and administrators.', summary: 'Create alert rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], responses: [
        new OA\Response(response: 201, description: 'Created'),
        new OA\Response(ref: '#/components/responses/PlatformUnprocessable', response: 422),
    ])]
    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => NotificationAlertRule::query()->create($this->validated($request))], 201);
    }

    #[OA\Put(path: '/api/v1/platform/notification-alert-rules/{id}', operationId: 'updateNotificationAlertRule', description: 'Updates an alert rule.', summary: 'Update alert rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Updated'),
        new OA\Response(ref: '#/components/responses/PlatformNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlatformUnprocessable', response: 422),
    ])]
    public function update(Request $request, string $id): JsonResponse
    {
        $rule = NotificationAlertRule::query()->findOrFail($id);
        $rule->update($this->validated($request));

        return response()->json(['data' => $rule->refresh()]);
    }

    #[OA\Delete(path: '/api/v1/platform/notification-alert-rules/{id}', operationId: 'deleteNotificationAlertRule', description: 'Deletes an alert rule.', summary: 'Delete alert rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Platform'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/PlatformNotFound', response: 404),
    ])]
    public function destroy(string $id): JsonResponse
    {
        NotificationAlertRule::query()->findOrFail($id)->delete();
        \DB::table('notification_alert_rule_hits')->where('rule_id', $id)->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'metric' => ['required', Rule::in(NotificationAlertRule::METRICS)],
            'threshold' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'category' => ['sometimes', Rule::in(config('platform.notification_categories'))],
            'recipient_user_ids' => ['nullable', 'array', 'max:50'],
            'recipient_user_ids.*' => ['uuid', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
