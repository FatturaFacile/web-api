<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GdoTradeTerms\Domain\Enums\ContributionMode;
use Modules\GdoTradeTerms\Domain\Enums\DateBasis;
use Modules\GdoTradeTerms\Domain\Enums\FixedAccrual;
use Modules\GdoTradeTerms\Domain\Enums\PromotionType;

final class SavePromotionRequest extends FormRequest
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

        return [
            'code' => [$required, 'string', 'max:40'],
            'name' => [$required, 'string', 'max:160'],
            'promotion_type' => [$required, Rule::in(PromotionType::values())],
            'date_basis' => [$required, Rule::in(DateBasis::values())],
            'starts_on' => [$required, 'date'],
            'ends_on' => [$required, 'date', 'after_or_equal:starts_on'],
            'contribution_mode' => [$required, Rule::in(ContributionMode::values())],
            'rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'unit_amount' => ['nullable', 'numeric', 'min:0'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'fixed_accrual' => ['nullable', Rule::in(FixedAccrual::values())],
            'cap_amount' => ['nullable', 'numeric', 'min:0'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['uuid'],
            'brand_ids' => ['nullable', 'array'],
            'brand_ids.*' => ['uuid'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['uuid'],
            'delivery_point_ids' => ['nullable', 'array'],
            'delivery_point_ids.*' => ['uuid'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
