<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Documents\Enums\RetentionAction;

final class StoreRetentionPolicyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'retention_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'action' => ['required', Rule::enum(RetentionAction::class)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
