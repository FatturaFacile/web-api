<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoyaltyCardRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();
        $creating = $this->isMethod('POST');

        return [
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'card_number' => ['nullable', 'string', 'max:50', Rule::unique('loyalty_cards', 'card_number')->where('tenant_id', $tenantId)->ignore($this->route('id'))],
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(['loyalty', 'voucher', 'prepaid'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['sometimes', Rule::in(['active', 'blocked'])],
            'expires_at' => ['nullable', 'date'],
            'initial_balance' => [Rule::prohibitedIf(! $creating), 'nullable', 'numeric', 'min:0'],
        ];
    }
}
