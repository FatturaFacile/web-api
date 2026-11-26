<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the SCIM provisioning log.
 */
class ListScimLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resource' => ['sometimes', 'nullable', Rule::in(['user', 'group'])],
            'status' => ['sometimes', 'nullable', Rule::in(['success', 'failure'])],
            'operation' => ['sometimes', 'nullable', Rule::in(['create', 'replace', 'patch', 'delete'])],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
