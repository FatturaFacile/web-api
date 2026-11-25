<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PassengerTransport\Http\Requests\Concerns\ScopesToTenant;

final class SaveSchoolContractRequest extends FormRequest
{
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'contact_id' => [$required, 'uuid', $this->tenantExists('contacts')],
            'code' => [$required, 'string', 'max:40', $this->tenantUnique('ptr_school_bus_contracts')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:160'],
            'currency' => ['sometimes', Rule::in(['CHF', 'EUR'])],
            'monthly_flat_amount' => ['nullable', 'numeric', 'min:0'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
            'rate_card' => ['nullable', 'array', 'max:200'],
            'rate_card.*.route_code' => ['required', 'string', 'max:40', 'distinct'],
            'rate_card.*.label' => ['required', 'string', 'max:160'],
            'rate_card.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
