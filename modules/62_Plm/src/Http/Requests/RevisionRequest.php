<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Requests;

/**
 * A draft revision of a manufacturing item. The item is fixed on creation.
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
            'change_summary' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->isMethod('POST') ? ['mfg_item_id' => ['required', 'uuid', $this->ownRow('mfg_items')], ...$rules] : $rules;
    }
}
