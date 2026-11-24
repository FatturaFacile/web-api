<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Associations\Support\TenantRules;

final class AssignRegistryTypeRequest extends FormRequest
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
            'registry_type_id' => ['required', 'uuid', TenantRules::exists('assoc_registry_types')],
            'year_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_years')],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
