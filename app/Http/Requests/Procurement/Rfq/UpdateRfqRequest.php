<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\Rfq;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates updates to a draft Request for Quotation.
 */
final class UpdateRfqRequest extends FormRequest
{
    use TenantScopedRules;

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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'currency_code' => ['sometimes', 'required', 'string', 'size:3'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'incoterms' => ['nullable', 'string', 'max:16'],
            'submission_deadline' => ['sometimes', 'required', 'date'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'sealed_bidding' => ['sometimes', 'boolean'],
            'evaluation_weights' => ['sometimes', 'array'],
            'evaluation_weights.price_weight' => ['required_with:evaluation_weights', 'integer', 'min:0', 'max:100'],
            'evaluation_weights.lead_time_weight' => ['required_with:evaluation_weights', 'integer', 'min:0', 'max:100'],
            'evaluation_weights.supplier_rating_weight' => ['required_with:evaluation_weights', 'integer', 'min:0', 'max:100'],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantExists('cost_centers')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'supplier_ids' => ['sometimes', 'array', 'min:1'],
            'supplier_ids.*' => ['required', 'uuid', $this->tenantOwns('suppliers')],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.source_requisition_line_id' => ['nullable', 'uuid', $this->tenantOwns('purchase_requisition_lines')],
            'lines.*.item_id' => ['nullable', 'uuid'],
            'lines.*.custom_item_code' => ['nullable', 'string', 'max:100'],
            'lines.*.internal_sku' => ['nullable', 'string', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.technical_specifications' => ['nullable', 'string', 'max:5000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_of_measure' => ['nullable', 'string', 'max:20'],
            'lines.*.uom' => ['nullable', 'string', 'max:20'],
            'lines.*.target_unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.requested_delivery_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $weights = $this->input('evaluation_weights');
            if (is_array($weights)) {
                $sum = (int) ($weights['price_weight'] ?? 0)
                    + (int) ($weights['lead_time_weight'] ?? 0)
                    + (int) ($weights['supplier_rating_weight'] ?? 0);

                if ($sum !== 100) {
                    $v->errors()->add('evaluation_weights', 'Evaluation weights must sum to 100.');
                }
            }
        });
    }
}
