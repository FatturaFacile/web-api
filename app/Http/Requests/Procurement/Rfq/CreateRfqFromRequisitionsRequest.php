<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement\Rfq;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates creating an RFQ from one or more approved Mod 044 Purchase Requisitions.
 */
final class CreateRfqFromRequisitionsRequest extends FormRequest
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
            'purchase_requisition_ids' => ['required', 'array', 'min:1'],
            'purchase_requisition_ids.*' => ['required', 'uuid', $this->tenantOwns('purchase_requisitions')],
            'line_ids' => ['nullable', 'array', 'min:1'],
            'line_ids.*' => ['required', 'uuid', $this->tenantOwns('purchase_requisition_lines')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'incoterms' => ['nullable', 'string', 'max:16'],
            'submission_deadline' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'sealed_bidding' => ['nullable', 'boolean'],
            'consolidate_matching_items' => ['nullable', 'boolean'],
            'evaluation_weights' => ['nullable', 'array'],
            'evaluation_weights.price_weight' => ['required_with:evaluation_weights', 'integer', 'min:0', 'max:100'],
            'evaluation_weights.lead_time_weight' => ['required_with:evaluation_weights', 'integer', 'min:0', 'max:100'],
            'evaluation_weights.supplier_rating_weight' => ['required_with:evaluation_weights', 'integer', 'min:0', 'max:100'],
            'supplier_ids' => ['required', 'array', 'min:1'],
            'supplier_ids.*' => ['required', 'uuid', $this->tenantOwns('suppliers')],
            'notes' => ['nullable', 'string', 'max:2000'],
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
