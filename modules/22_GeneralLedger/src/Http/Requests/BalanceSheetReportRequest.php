<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Date of the balance sheet and optional comparison date.
 */
final class BalanceSheetReportRequest extends FormRequest
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
            'as_of' => ['required', 'date'],
            'compare_as_of' => ['nullable', 'date'],
            'presentation_currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
