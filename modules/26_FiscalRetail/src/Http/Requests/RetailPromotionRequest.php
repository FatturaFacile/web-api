<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FiscalRetail\Models\RetailPromotion;

/**
 * Payload of a POS promotion. Percentage and fixed promotions need a value; buy X get Y needs a product and both
 * quantities. The time window needs both ends.
 */
final class RetailPromotionRequest extends FormRequest
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
        $isUpdate = $this->isMethod('PUT');
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:40', Rule::unique('retail_promotions', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:190'],
            'type' => [$required, Rule::in(RetailPromotion::Types)],
            'value' => ['nullable', 'numeric', 'gt:0', 'required_if:type,percentage', 'required_if:type,fixed', Rule::when($this->input('type') === 'percentage', ['max:100'])],
            'product_id' => ['nullable', 'uuid', 'required_if:type,buy_x_get_y', Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'buy_quantity' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_if:type,buy_x_get_y'],
            'get_quantity' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_if:type,buy_x_get_y'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'time_from' => ['nullable', 'date_format:H:i', 'required_with:time_to'],
            'time_to' => ['nullable', 'date_format:H:i', 'required_with:time_from'],
            'priority' => ['nullable', 'integer', 'between:-1000,1000'],
            'is_stackable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
