<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\ApprovalMode;
use Modules\Associations\Support\TenantRules;

final class SaveEnrolmentFormRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'year_id' => ['required', 'uuid', TenantRules::exists('assoc_years')],
            'membership_type_id' => ['required', 'uuid', TenantRules::exists('assoc_membership_types')],
            'approval_mode' => ['sometimes', Rule::enum(ApprovalMode::class)],
            'is_active' => ['sometimes', 'boolean'],
            'opens_at' => ['sometimes', 'nullable', 'date'],
            'closes_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:opens_at'],
            'fields' => ['sometimes', 'nullable', 'array', 'max:50'],
            'fields.*.code' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.type' => ['sometimes', Rule::in(['text', 'number', 'date', 'boolean', 'select'])],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.options' => ['sometimes', 'array'],
            'fields.*.options.*' => ['string', 'max:255'],
        ];
    }
}
