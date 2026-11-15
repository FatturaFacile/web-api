<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Requests;

/**
 * Settlement of the open accruals: the VAT rate of the credit note line (customers) and its date.
 */
final class SettleRequest extends RebatesFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settled_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
