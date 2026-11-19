<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

/**
 * Manual top-up (positive hours) or adjustment (signed hours) of a contract hour bank.
 */
final class HourBankEntryRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'numeric', 'regex:/^-?\d{1,8}(\.\d{1,2})?$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
