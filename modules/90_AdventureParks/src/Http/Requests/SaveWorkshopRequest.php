<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Models\EducationalWorkshop;

final class SaveWorkshopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', EducationalWorkshop::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => $creating ? ['required', 'string', 'regex:/^[A-Za-z0-9_]{2,40}$/'] : ['prohibited'],
            'duration_minutes' => [$required, 'integer', 'between:10,720'],
            'max_participants' => [$required, 'integer', 'between:1,500'],
            'price_per_student' => [$required, 'numeric', 'min:0', 'max:9999'],
            'name_translations' => ['nullable', 'array'],
            'name_translations.*' => ['nullable', 'string', 'max:150'],
            'description_translations' => ['nullable', 'array'],
            'description_translations.*' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
