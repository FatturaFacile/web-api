<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreIntercompanyRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entity_a_tenant_id' => ['required', 'uuid'],
            'entity_b_tenant_id' => ['required', 'uuid', 'different:entity_a_tenant_id'],
            'tolerance_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
