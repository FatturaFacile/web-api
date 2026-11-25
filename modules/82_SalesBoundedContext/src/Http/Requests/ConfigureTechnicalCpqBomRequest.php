<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfigureTechnicalCpqBomRequest extends FormRequest
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
            'dossier_id' => ['nullable', 'uuid'],
            'quote_id' => ['nullable', 'uuid'],
            'configuration_code' => ['nullable', 'string', 'max:40'],
            'product_family' => ['required', 'string', 'max:120'],
            'selected_options' => ['nullable', 'array'],
            'super_bom_items' => ['nullable', 'array'],
            'routing_operations' => ['nullable', 'array'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
