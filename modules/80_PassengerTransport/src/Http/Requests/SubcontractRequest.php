<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PassengerTransport\Http\Requests\Concerns\ScopesToTenant;

final class SubcontractRequest extends FormRequest
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
            'action' => ['required', Rule::in(['cede', 'acquire', 'release'])],
            'carrier_id' => ['required_unless:action,release', 'nullable', 'uuid', $this->tenantExists('contacts')],
            'amount' => ['required_unless:action,release', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
