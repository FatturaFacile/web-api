<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Date and optional currency of a revaluation of open receivables and payables.
 */
final class StoreFxRevaluationRequest extends FormRequest
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
            'revaluation_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ];
    }
}
