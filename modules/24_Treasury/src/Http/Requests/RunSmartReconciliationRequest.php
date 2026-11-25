<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Runs the smart engine on one imported statement, optionally applying the confident, unambiguous proposals.
 */
final class RunSmartReconciliationRequest extends FormRequest
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
            'statement_id' => ['required', 'uuid'],
            'auto_apply' => ['sometimes', 'boolean'],
        ];
    }
}
