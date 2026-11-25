<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CalculateFranchisingRoyaltyRequest extends FormRequest
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
            'dossier_id' => ['nullable', 'uuid'],
            'affiliate_code' => ['nullable', 'string', 'max:40'],
            'franchisor_brand' => ['nullable', 'string', 'max:120'],
            'store_name' => ['required', 'string', 'max:160'],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sell_out_turnover' => ['required', 'numeric', 'min:0'],
            'royalty_rate_pct' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'marketing_fund_rate_pct' => ['nullable', 'numeric', 'min:0', 'max:15'],
            'pushed_price_list_version' => ['nullable', 'string', 'max:40'],
            'network_avg_turnover' => ['nullable', 'numeric', 'min:1'],
        ];
    }
}
