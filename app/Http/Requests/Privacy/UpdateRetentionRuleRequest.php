<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use App\Enums\Privacy\RetentionExpiryAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRetentionRuleRequest extends FormRequest
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
            'retention_days' => ['sometimes', 'integer', 'min:1', 'max:36500'],
            'action_on_expiry' => ['sometimes', Rule::enum(RetentionExpiryAction::class)],
            'is_enabled' => ['sometimes', 'boolean'],
            'legal_basis_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
