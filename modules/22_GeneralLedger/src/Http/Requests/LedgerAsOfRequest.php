<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The day up to which scheduled ledger work runs (recurring journals, automatic reversals); today when omitted.
 */
final class LedgerAsOfRequest extends FormRequest
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
            'as_of' => ['nullable', 'date'],
        ];
    }
}
