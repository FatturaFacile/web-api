<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The contract and the period to bill (a month, normally).
 */
final class BillingPeriodRequest extends FormRequest
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
            'contract_id' => ['required', 'uuid'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'create_invoice' => ['sometimes', 'boolean'],
        ];
    }
}
