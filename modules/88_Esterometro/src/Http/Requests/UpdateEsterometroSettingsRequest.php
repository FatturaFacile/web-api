<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEsterometroSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'auto_generate_mode' => ['sometimes', 'string', 'in:auto_draft,auto_validate,manual'],
            'auto_post_vat_registers' => ['sometimes', 'boolean'],
            'auto_post_journal' => ['sometimes', 'boolean'],
            'sales_vat_section_code' => ['sometimes', 'string', 'max:20'],
            'purchase_vat_section_code' => ['sometimes', 'string', 'max:20'],
            'numbering_prefix' => ['sometimes', 'string', 'max:20'],
            'numbering_padding' => ['sometimes', 'integer', 'min:3', 'max:8'],
            'default_recipient_code' => ['sometimes', 'string', 'size:7'],
            'use_tenant_sdi_code' => ['sometimes', 'boolean'],
            'signature_format' => ['sometimes', 'string', 'in:none,cades,xades'],
            'clearing_account_id' => ['nullable', 'uuid'],
            'non_deductible_account_id' => ['nullable', 'uuid'],
        ];
    }
}
