<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class SaveKnowledgeArticleRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:200'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'content' => [$required, 'string', 'max:200000'],
            'knowledge_category_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_knowledge_categories')],
            'category_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('esm_service_categories')],
            'visibility' => ['sometimes', 'string', 'in:internal,public'],
            'is_published' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*' => ['string', 'max:50'],
            'change_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
