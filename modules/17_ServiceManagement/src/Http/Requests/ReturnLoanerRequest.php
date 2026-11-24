<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class ReturnLoanerRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'returned_at' => ['nullable', 'date'],
        ];
    }
}
