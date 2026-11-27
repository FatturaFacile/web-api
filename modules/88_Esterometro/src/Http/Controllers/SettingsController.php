<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Esterometro\Http\Requests\UpdateEsterometroSettingsRequest;
use Modules\Esterometro\Http\Resources\SelfInvoiceSettingResource;
use Modules\Esterometro\Models\SelfInvoiceSetting;
use OpenApi\Attributes as OA;

final class SettingsController extends Controller
{
    #[OA\Get(
        path: '/api/v1/esterometro/settings',
        summary: 'Get Esterometro & Reverse Charge settings for the current tenant',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Tenant settings')],
    )]
    public function show(Request $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $settings = SelfInvoiceSetting::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'auto_generate_mode' => 'auto_draft',
                'auto_post_vat_registers' => true,
                'auto_post_journal' => true,
                'sales_vat_section_code' => 'VEN-AUT',
                'purchase_vat_section_code' => 'ACQ-RC',
                'numbering_prefix' => 'AF-',
                'numbering_padding' => 5,
                'default_recipient_code' => '0000000',
                'use_tenant_sdi_code' => true,
                'signature_format' => 'none',
            ],
        );

        return (new SelfInvoiceSettingResource($settings))->response();
    }

    #[OA\Put(
        path: '/api/v1/esterometro/settings',
        summary: 'Update Esterometro & Reverse Charge settings for the current tenant',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Tenant settings updated')],
    )]
    public function update(UpdateEsterometroSettingsRequest $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $settings = SelfInvoiceSetting::query()->firstOrCreate(['tenant_id' => $tenantId]);
        $settings->update($request->validated());

        return (new SelfInvoiceSettingResource($settings->fresh() ?? $settings))->response();
    }
}
