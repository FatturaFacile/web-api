<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cash-discount terms of one document; both fields null remove the override and the supplier terms apply again.
 */
final class SkontoTermsOverrideRequest extends FormRequest
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
            'skonto_percentage' => ['present', 'nullable', 'numeric', 'gt:0', 'max:100', 'required_with:skonto_days'],
            'skonto_days' => ['present', 'nullable', 'integer', 'min:0', 'max:365', 'required_with:skonto_percentage'],
        ];
    }
}
