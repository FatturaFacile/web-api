<?php

declare(strict_types=1);

namespace Modules\SwissVat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBezugsteuerRequest extends FormRequest
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
            'supplier_id' => ['nullable', 'uuid'],
            'supplier_name' => ['required', 'string', 'max:190'],
            'supplier_country' => ['required', 'string', 'size:2'],
            'supplier_vat_number' => ['nullable', 'string', 'max:50'],
            'invoice_number' => ['nullable', 'string', 'max:80'],
            'invoice_date' => ['required', 'date'],
            'service_category' => ['nullable', 'string', Rule::in(['software_cloud', 'consulting', 'it_services', 'telecom', 'royalties', 'marketing', 'other_cross_border_service', 'import_service'])],
            'description' => ['required', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'original_net_amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'vat_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
