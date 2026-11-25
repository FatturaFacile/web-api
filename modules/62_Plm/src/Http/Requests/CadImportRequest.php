<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Plm\Enums\CadSourceSystem;

final class CadImportRequest extends PlmFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cad_source_system' => ['required', Rule::in(CadSourceSystem::values())],
            'product_code' => ['required', 'string', 'max:80'],
            'revision_code' => ['sometimes', 'string', 'max:20'],
            'bom_name' => ['nullable', 'string', 'max:160'],
            'raw_payload' => ['nullable', 'string', 'max:500000'],
            'payload_format' => ['nullable', Rule::in(['xml', 'csv', 'step', 'stp'])],
            'parts' => ['nullable', 'array', 'max:500'],
            'parts.*.item_code' => ['required_with:parts', 'string', 'max:80'],
            'parts.*.name' => ['nullable', 'string', 'max:160'],
            'parts.*.quantity' => ['required_with:parts', 'numeric', 'gt:0'],
            'parts.*.uom' => ['nullable', 'string', 'max:20'],
            'parts.*.unit_cost' => ['nullable', 'numeric', 'gte:0'],
            'parts.*.level' => ['nullable', 'integer', 'min:1', 'max:25'],
            'parts.*.parent_code' => ['nullable', 'string', 'max:80'],
            'parts.*.drawing_number' => ['nullable', 'string', 'max:80'],
            'source_file_metadata' => ['nullable', 'array'],
            'cad_attachments' => ['nullable', 'array', 'max:20'],
            'auto_create_missing_parts' => ['sometimes', 'boolean'],
            'commit_to_mbom' => ['sometimes', 'boolean'],
        ];
    }
}
