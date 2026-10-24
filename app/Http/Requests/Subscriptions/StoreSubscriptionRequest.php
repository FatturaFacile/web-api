<?php

declare(strict_types=1);

namespace App\Http\Requests\Subscriptions;

use App\Enums\SubscriptionInterval;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionRequest extends FormRequest
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
            'contact_id' => [$presence, 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'name' => [$presence, 'string', 'max:255'],
            'interval' => [$presence, Rule::enum(SubscriptionInterval::class)],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'currency' => [$presence, 'string', 'size:3'],
            'start_date' => [$presence, 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'auto_renew' => ['nullable', 'boolean'],
            'notify_days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => [$presence, 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
