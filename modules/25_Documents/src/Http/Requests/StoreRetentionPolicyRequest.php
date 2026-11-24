<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Documents\Enums\RetentionAction;
use Modules\Documents\Services\DocumentRetention;

/**
 * Validates the input of the store retention policy operation (authorization is decided by module.access). A delete
 * policy on a document type under statutory retention must last at least the statutory period.
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

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['document_type', 'retention_months', 'action'])) {
                return;
            }

            $conflict = DocumentRetention::statutoryPurgeConflict($this->input('document_type'), (string) $this->input('action'), (int) $this->input('retention_months'));

            if ($conflict !== null) {
                $validator->errors()->add('retention_months', $conflict);
            }
        }];
    }
}
