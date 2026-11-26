<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\UpdateTenantReportSettingRequest;
use App\Http\Resources\Reports\TenantReportSettingResource;
use App\Http\Responses\ApiResponse;
use App\Models\ScheduledReportAuditLog;
use App\Models\TenantReportSetting;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class TenantReportSettingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/tenant-report-settings',
        operationId: 'getTenantReportSettings',
        description: 'Retrieve tenant-level branding, timezone, sender, and default settings for scheduled ERP reports.',
        summary: 'Get tenant report settings',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        responses: [
            new OA\Response(response: 200, description: 'Tenant report settings retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $tenantId = $tenant !== null ? (string) $tenant->id : '';

        $settings = TenantReportSetting::query()->firstOrNew(
            ['tenant_id' => $tenantId],
            ['timezone' => TenantReportSetting::defaultTimezoneForTenant($tenant)]
        );

        return $this->successResponse(
            data: (new TenantReportSettingResource($settings))->resolve($request),
            message: 'Tenant report settings retrieved'
        );
    }

    #[OA\Put(
        path: '/api/v1/tenant-report-settings',
        operationId: 'updateTenantReportSettings',
        description: 'Update tenant-level branding, timezone, sender, enabled modules, and rate limits for scheduled ERP reports.',
        summary: 'Update tenant report settings',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Scheduled ERP Reports'],
        responses: [
            new OA\Response(response: 200, description: 'Tenant report settings updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateTenantReportSettingRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $this->tenantContext->getTenant();
        $tenantId = $tenant !== null ? (string) $tenant->id : '';

        $settings = TenantReportSetting::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            $request->validated()
        );

        ScheduledReportAuditLog::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'event_type' => ScheduledReportAuditLog::EVENT_TENANT_SETTINGS_UPDATED,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'metadata' => [
                'timezone' => $settings->timezone,
                'default_frequency' => $settings->default_frequency,
                'enabled_modules' => $settings->effectiveEnabledModules(),
            ],
            'occurred_at' => now(),
        ]);

        return $this->successResponse(
            data: (new TenantReportSettingResource($settings->refresh()))->resolve($request),
            message: 'Tenant report settings saved'
        );
    }
}
