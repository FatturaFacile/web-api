<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IndexCopilotKnowledgeRequest extends FormRequest
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
            'documents' => ['required', 'array', 'min:1', 'max:50'],
            'documents.*.title' => ['required', 'string', 'max:200'],
            'documents.*.section_ref' => ['nullable', 'string', 'max:120'],
            'documents.*.content' => ['required', 'string', 'max:200000'],
            'documents.*.jurisdiction' => ['required', 'in:CH,IT,GLOBAL'],
            'documents.*.locale' => ['required', 'in:it,de,fr,en'],
        ];
    }
}
