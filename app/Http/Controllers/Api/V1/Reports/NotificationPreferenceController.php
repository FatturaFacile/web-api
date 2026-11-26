<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\UpdateScheduledNotificationPreferenceRequest;
use App\Http\Resources\Reports\NotificationPreferenceResource;
use App\Http\Resources\Reports\TenantReportSettingResource;
use App\Http\Responses\ApiResponse;
use App\Models\NotificationPreference;
use App\Models\ScheduledReportAuditLog;
use App\Models\Tenant;
use App\Models\TenantReportSetting;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class NotificationPreferenceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/notification-preferences',
        operationId: 'getScheduledNotificationPreferences',
        description: 'Retrieve the authenticated user scheduled ERP daily report preferences and tenant report defaults.',
        summary: 'Get scheduled ERP report notification preferences',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        responses: [
            new OA\Response(response: 200, description: 'Preferences retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $this->tenantContext->getTenant();
        $tenantId = $tenant !== null ? (string) $tenant->id : '';

        $settings = TenantReportSetting::query()->firstOrNew(
            ['tenant_id' => $tenantId],
            ['timezone' => TenantReportSetting::defaultTimezoneForTenant($tenant)]
        );

        $preference = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->first();

        if ($preference === null) {
            $preference = new NotificationPreference([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'enabled' => true,
                'frequency' => $settings->default_frequency,
                'send_time' => $settings->default_send_time,
                'language' => $this->defaultLanguageForTenant($tenant),
            ]);
        }

        return $this->successResponse(
            data: [
                'preference' => (new NotificationPreferenceResource($preference))->resolve($request),
                'tenant_settings' => (new TenantReportSettingResource($settings))->resolve($request),
                'available_modules' => NotificationPreference::MODULES,
                'supported_languages' => NotificationPreference::SUPPORTED_LANGUAGES,
                'frequencies' => NotificationPreference::FREQUENCIES,
            ],
            message: 'Scheduled report notification preferences retrieved'
        );
    }

    #[OA\Put(
        path: '/api/v1/notification-preferences',
        operationId: 'updateScheduledNotificationPreferences',
        description: 'Create or update the authenticated user scheduled ERP daily report preferences.',
        summary: 'Update scheduled ERP report notification preferences',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        responses: [
            new OA\Response(response: 200, description: 'Preferences updated successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateScheduledNotificationPreferenceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $this->tenantContext->getTenant();
        $tenantId = $tenant !== null ? (string) $tenant->id : '';
        $validated = $request->validated();

        $existing = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->first();

        $isNew = $existing === null;

        $preference = $existing ?? new NotificationPreference([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
        ]);

        $preference->fill($validated);
        if ((bool) $validated['enabled'] === true) {
            $preference->unsubscribed_at = null;
        }
        $preference->save();

        ScheduledReportAuditLog::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'event_type' => $isNew
                ? ScheduledReportAuditLog::EVENT_PREFERENCE_CREATED
                : ScheduledReportAuditLog::EVENT_PREFERENCE_UPDATED,
            'recipient_email' => $user->email,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'metadata' => [
                'frequency' => $preference->frequency,
                'send_time' => $preference->send_time,
                'language' => $preference->language,
                'enabled' => $preference->enabled,
            ],
            'occurred_at' => now(),
        ]);

        return $this->successResponse(
            data: (new NotificationPreferenceResource($preference->refresh()))->resolve($request),
            message: 'Scheduled report notification preferences saved'
        );
    }

    private function defaultLanguageForTenant(?Tenant $tenant): string
    {
        return ($tenant !== null ? $tenant->country : 'CH') === 'IT' ? 'it-IT' : 'it-CH';
    }
}
