<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Enums\SupplierQualificationStatus;

/**
 * New qualification status of a supplier with the next review date.
 */
final class UpdateSupplierQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'qualification_status' => ['required', 'string', 'in:'.implode(',', SupplierQualificationStatus::values())],
            'review_date' => ['nullable', 'date_format:Y-m-d'],
            'over_receipt_tolerance_percent' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
            'price_tolerance_percent' => ['nullable', 'decimal:0,2', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
