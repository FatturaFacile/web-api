<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SDI\Enums\NaturaIva;

final class GenerateIntegrationRequest extends FormRequest
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
            'supply_kind' => ['nullable', Rule::in(['goods', 'services'])],
            'date' => ['nullable', 'date'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_nature' => ['nullable', Rule::in(NaturaIva::issuable())],
            'description' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
