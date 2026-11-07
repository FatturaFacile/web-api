<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Customs\Enums\CustomsChannel;

final class SaveCustomsSettingsRequest extends FormRequest
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
            'channel' => ['sometimes', Rule::enum(CustomsChannel::class)],
            'auto_retrieve' => ['sometimes', 'boolean'],
            'trader_identification_number' => ['sometimes', 'nullable', 'string', 'max:17'],
            'declarant_number' => ['sometimes', 'nullable', 'integer', 'between:0,999999'],
            'declarant_name' => ['sometimes', 'nullable', 'string', 'max:35'],
            'declarant_street' => ['sometimes', 'nullable', 'string', 'max:35'],
            'declarant_postal_code' => ['sometimes', 'nullable', 'string', 'max:9'],
            'declarant_city' => ['sometimes', 'nullable', 'string', 'max:35'],
            'declarant_country' => ['sometimes', 'nullable', ...CustomsRules::country()],
            'customs_account' => ['sometimes', 'nullable', 'digits_between:1,8'],
            'vat_account' => ['sometimes', 'nullable', 'digits_between:1,8'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:12'],
            'receipt_role' => ['sometimes', 'nullable', 'integer', 'in:0,1,2'],
            'duty_expense_account_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('chart_of_accounts')],
            'customs_payable_account_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('chart_of_accounts')],
            'import_vat_account_id' => ['sometimes', 'nullable', 'uuid', CustomsRules::exists('chart_of_accounts')],
        ];
    }
}
