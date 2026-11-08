<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Documents\Enums\RetentionAction;

/**
 * Validates the input of the store retention policy operation (authorization is decided by module.access).
 */
final class StoreRetentionPolicyRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
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
