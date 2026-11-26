<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The audit export of the rates in use over a period.
 */
final class ExportRatesRequest extends FormRequest
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['sometimes', 'string', Rule::in(['csv', 'xlsx'])],
        ];
    }
}
