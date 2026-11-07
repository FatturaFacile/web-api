<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class SaveKnowledgeCategoryRequest extends FormRequest
{
    use TenantScopedRules;

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
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:150'],
            'parent_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_knowledge_categories'), 'not_in:'.(string) $this->route('id')],
            'visibility' => ['sometimes', 'string', 'in:internal,public'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
