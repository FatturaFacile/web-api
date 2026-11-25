<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Plm\Enums\RevisionLifecycleState;

/**
 * A draft revision of a manufacturing item with Mod 168 lifecycle, eBOM/mBOM, CAD files, and compliance flags.
 */
final class RevisionRequest extends PlmFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'revision' => ['required', 'string', 'max:20'],
            'parent_revision_id' => ['nullable', 'uuid', $this->ownRow('plm_item_revisions')],
            'engineering_part_number' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'lifecycle_state' => ['sometimes', Rule::in(RevisionLifecycleState::values())],
            'ebom_structure_json' => ['nullable', 'array'],
            'mbom_id' => ['nullable', 'uuid', $this->ownRow('mfg_boms')],
            'routing_id' => ['nullable', 'uuid', $this->ownRow('mfg_routings')],
            'cad_drawing_pdf_and_step_file_ids' => ['nullable', 'array'],
            'rohs_and_reach_compliant' => ['sometimes', 'boolean'],
            'conflict_minerals_compliant' => ['sometimes', 'boolean'],
            'technical_file_ref' => ['nullable', 'string', 'max:120'],
            'change_summary' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->isMethod('POST') ? ['mfg_item_id' => ['required', 'uuid', $this->ownRow('mfg_items')], ...$rules] : $rules;
    }
}
