<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AutoDetectMappingRequest extends FormRequest
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
            'template_id' => ['nullable', 'uuid'],
            'mappings_by_entity' => ['nullable', 'array'],
            'value_transformations' => ['nullable', 'array'],
        ];
    }
}
