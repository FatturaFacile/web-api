<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\UpdateLeadSettingRequest;
use Modules\CRM\Http\Resources\LeadSettingResource;
use Modules\CRM\Models\LeadSetting;

final class LeadSettingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function show(): JsonResponse
    {
        $settings = LeadSetting::forTenant((string) $this->tenantContext->getTenantId());
        $this->authorize('view', $settings);

        return (new LeadSettingResource($settings))->response();
    }

    public function update(UpdateLeadSettingRequest $request): JsonResponse
    {
        $settings = LeadSetting::forTenant((string) $this->tenantContext->getTenantId());
        $this->authorize('update', $settings);

        $data = $request->validated();
        if (isset($data['default_country'])) {
            $data['default_country'] = strtoupper((string) $data['default_country']);
        }

        $settings->fill($data)->save();

        return (new LeadSettingResource($settings))->response();
    }
}
