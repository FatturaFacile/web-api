<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SearchKnowledgeArticlesRequest extends FormRequest
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
            'q' => ['sometimes', 'string', 'max:200'],
            'visibility' => ['sometimes', 'string', 'in:internal,public'],
            'knowledge_category_id' => ['sometimes', 'uuid'],
            'is_published' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
