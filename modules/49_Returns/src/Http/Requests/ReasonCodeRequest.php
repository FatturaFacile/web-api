<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A reason code of the returns settings.
 */
final class ReasonCodeRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
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
        $rules = [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'max:120'],
            'is_defect' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($this->isMethod('PUT')) {
            unset($rules['code']);
            $rules['label'] = ['sometimes', ...$rules['label']];
        }

        return $rules;
    }
}
