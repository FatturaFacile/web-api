<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Esterometro\Models\SelfInvoiceSetting;

/**
 * @mixin SelfInvoiceSetting
 */
final class SelfInvoiceSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SelfInvoiceSetting $s */
        $s = $this->resource;

        return [
            'id' => $s->id,
            'tenant_id' => $s->tenant_id,
            'auto_generate_mode' => $s->auto_generate_mode,
            'auto_post_vat_registers' => $s->auto_post_vat_registers,
            'auto_post_journal' => $s->auto_post_journal,
            'sales_vat_section_code' => $s->sales_vat_section_code,
            'purchase_vat_section_code' => $s->purchase_vat_section_code,
            'numbering_prefix' => $s->numbering_prefix,
            'numbering_padding' => $s->numbering_padding,
            'default_recipient_code' => $s->default_recipient_code,
            'use_tenant_sdi_code' => $s->use_tenant_sdi_code,
            'signature_format' => $s->signature_format,
            'clearing_account_id' => $s->clearing_account_id,
            'non_deductible_account_id' => $s->non_deductible_account_id,
        ];
    }
}
