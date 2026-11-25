<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\ElectronicSignatureStandard;

final class StoreCpqQuoteRequest extends FormRequest
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
            'opportunity_id' => ['nullable', 'uuid'],
            'quote_number' => ['nullable', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:180'],
            'valid_until' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_terms_code' => ['nullable', 'string', 'max:40'],
            'signature_standard' => ['nullable', Rule::in(ElectronicSignatureStandard::values())],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.is_optional' => ['nullable', 'boolean'],
            'lines.*.is_selected' => ['nullable', 'boolean'],
            'lines.*.is_bundle' => ['nullable', 'boolean'],
        ];
    }
}
