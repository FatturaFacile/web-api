<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Customs\Enums\ChargeCategory;
use Modules\Customs\Enums\ChargeKind;

final class SaveChargeRequest extends FormRequest
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
        return [
            'kind' => ['required', Rule::enum(ChargeKind::class)],
            'category' => ['required', Rule::enum(ChargeCategory::class)],
            'is_direct' => ['sometimes', 'boolean'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'between:-999999999999,999999999999'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'supplier_contact_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('contacts')],
        ];
    }
}
