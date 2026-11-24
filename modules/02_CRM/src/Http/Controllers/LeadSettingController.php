<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\UpdateLeadSettingRequest;
use Modules\CRM\Http\Resources\LeadSettingResource;
use Modules\CRM\Models\LeadSetting;
use OpenApi\Attributes as OA;

final class LeadSettingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/crm/lead-settings',
        operationId: 'showCrmLeadSettings',
        summary: 'Show lead settings',
        tags: ['CRM'],
        responses: [new OA\Response(response: 200, description: 'Lead settings')]
    )]
    public function show(): JsonResponse
    {
        $settings = LeadSetting::forTenant((string) $this->tenantContext->getTenantId());
        $this->authorize('view', $settings);

        return (new LeadSettingResource($settings))->response();
    }

    #[OA\Put(
        path: '/api/v1/crm/lead-settings',
        operationId: 'updateCrmLeadSettings',
        summary: 'Update lead settings',
        tags: ['CRM'],
        responses: [new OA\Response(response: 200, description: 'Lead settings updated')]
    )]
    public function update(UpdateLeadSettingRequest $request): JsonResponse
    {
        $settings = LeadSetting::forTenant((string) $this->tenantContext->getTenantId());
        $this->authorize('update', $settings);

        $data = $request->validated();
        if (isset($data['default_country'])) {
            $data['default_country'] = strtoupper((string) $data['default_country']);
        }

        $settings->fill($data)->save();

        return (new LeadSettingResource($settings))->response()->setStatusCode(200);
    }
}
