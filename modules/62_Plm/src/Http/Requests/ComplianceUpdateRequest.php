<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Requests;

final class ComplianceUpdateRequest extends PlmFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'strategy_code' => ['sometimes', 'string', 'max:60'],
            'dossier_code' => ['nullable', 'string', 'max:60'],
            'machinery_directive_ref' => ['nullable', 'string', 'max:120'],
            'ce_declaration_status' => ['sometimes', 'string', 'max:24'],
            'ukca_declaration_status' => ['sometimes', 'string', 'max:24'],
            'omacch_mra_ch_eu_compliant' => ['sometimes', 'boolean'],
            'rohs_and_reach_compliant' => ['sometimes', 'boolean'],
            'conflict_minerals_compliant' => ['sometimes', 'boolean'],
            'technical_file_ref' => ['nullable', 'string', 'max:120'],
            'checklist_items_json' => ['nullable', 'array'],
        ];
    }
}
