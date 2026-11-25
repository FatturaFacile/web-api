<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\BillingModel;
use Modules\IndustrialLaundry\Domain\Enums\CustomerSegment;
use Modules\IndustrialLaundry\Domain\Enums\Jurisdiction;
use Modules\IndustrialLaundry\Domain\Enums\KgBasis;

final class SaveParContractRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'code' => [$required, 'string', 'max:40', Rule::unique('ilr_par_contracts', 'code')->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))->ignore($this->route('id'))],
            'customer_id' => [$required, 'uuid'],
            'customer_segment' => ['nullable', Rule::in(CustomerSegment::values())],
            'valid_from' => [$required, 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
            'billing_model' => ['nullable', Rule::in(BillingModel::values())],
            'kg_basis' => ['nullable', Rule::in(KgBasis::values())],
            'unreturned_garment_replacement_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'unreturned_threshold_days' => ['nullable', 'integer', 'between:1,3650'],
            'jurisdiction' => ['nullable', Rule::in(Jurisdiction::values())],
            'currency' => ['nullable', 'string', 'size:3'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'max:20'],
            'lines.*.article_type' => ['required', Rule::in(ArticleType::values()), 'distinct'],
            'lines.*.product_id' => ['nullable', 'uuid'],
            'lines.*.label' => ['nullable', 'string', 'max:160'],
            'lines.*.circulating_stock_qty' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lines.*.weekly_min_billing_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.seasonal_min_billing_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.season_start_month' => ['nullable', 'integer', 'between:1,12'],
            'lines.*.season_end_month' => ['nullable', 'integer', 'between:1,12'],
            'lines.*.unit_wash_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.price_per_kg' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.rental_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.replacement_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }
}
