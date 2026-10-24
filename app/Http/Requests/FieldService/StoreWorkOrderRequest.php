<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldService;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'client_id' => [$presence, 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'ticket_id' => ['nullable', 'uuid', Rule::exists('tickets', 'id')->where('tenant_id', $tenantId)],
            'technician_user_id' => ['nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId)],
            'date' => [$presence, 'date'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'intervention_address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => [$presence, 'array', 'min:1'],
            'lines.*.type' => ['nullable', Rule::in(['labor', 'material', 'travel'])],
            'lines.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'lines.*.description' => ['required', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
