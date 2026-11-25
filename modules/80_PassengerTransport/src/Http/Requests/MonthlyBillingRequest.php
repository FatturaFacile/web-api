<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\PassengerTransport\Http\Requests\Concerns\ScopesToTenant;

final class MonthlyBillingRequest extends FormRequest
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
        return [
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'contact_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('contacts')],
            'contact_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'contact_ids.*' => ['uuid', $this->tenantExists('contacts')],
        ];
    }
}
