<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class ResolveTicketRequest extends FormRequest
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
            'resolution_notes' => ['nullable', 'string'],
            'root_cause' => ['nullable', 'string'],
            'knowledge_article_id' => ['nullable', 'uuid', $this->tenantOwns('esm_knowledge_articles')],
        ];
    }
}
