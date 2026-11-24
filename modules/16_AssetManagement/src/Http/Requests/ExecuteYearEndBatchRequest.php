<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for year-end parallel depreciation batch execution (Civil + Fiscal).
 */
final class ExecuteYearEndBatchRequest extends FormRequest
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
            'year' => ['required', 'integer', 'min:1900', 'max:2200'],
            'auto_post_to_gl' => ['nullable', 'boolean'],
        ];
    }
}
