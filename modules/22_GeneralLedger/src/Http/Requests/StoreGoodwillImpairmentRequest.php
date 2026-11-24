<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreGoodwillImpairmentRequest extends FormRequest
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
            'fiscal_year' => ['required', 'integer', 'between:1900,2200'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ];
    }
}
