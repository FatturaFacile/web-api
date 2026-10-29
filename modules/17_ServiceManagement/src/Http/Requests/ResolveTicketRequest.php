<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_notes' => ['nullable', 'string'],
            'root_cause' => ['nullable', 'string'],
            'knowledge_article_id' => ['nullable', 'uuid'],
        ];
    }
}
