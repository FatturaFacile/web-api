<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AdventureParks\Models\SlotTemplate;

final class SaveSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', SlotTemplate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => [$required, 'date_format:H:i', 'after:start_time'],
            'wristband_color' => ['nullable', 'string', Rule::in((array) config('adventure_parks.wristband_palette'))],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
