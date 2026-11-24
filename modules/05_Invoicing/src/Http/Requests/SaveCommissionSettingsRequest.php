<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveCommissionSettingsRequest extends FormRequest
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
            'expense_account_id' => ['required', 'uuid', SaveSalesTargetRequest::existsInTenant('chart_of_accounts')],
            'payable_account_id' => ['required', 'uuid', SaveSalesTargetRequest::existsInTenant('chart_of_accounts')],
        ];
    }
}
