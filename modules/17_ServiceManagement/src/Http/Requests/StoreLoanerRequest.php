<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Enums\DdtCausale;
use Modules\ServiceManagement\Enums\LoanerRegisterType;

final class StoreLoanerRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'loaner_serial_number' => ['required_without:loaner_stock_serial_number_id', 'nullable', 'string', 'max:100'],
            'loaner_stock_serial_number_id' => ['required_without:loaner_serial_number', 'nullable', 'uuid'],
            'loaner_product_id' => ['nullable', 'uuid'],
            'register_type' => ['required', 'string', Rule::enum(LoanerRegisterType::class)],
            'issued_at' => ['nullable', 'date'],
            'expected_return_at' => ['nullable', 'date'],
            'ddt_causale' => ['nullable', 'string', Rule::enum(DdtCausale::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
