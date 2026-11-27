<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveSettingsRequest extends FormRequest
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
        $account = ['nullable', 'uuid', Rule::exists('chart_of_accounts', 'id')->where('tenant_id', $tenantId)];

        return [
            'auto_intercept' => ['sometimes', 'boolean'],
            'receivable_account_id' => $account,
            'non_recoverable_expense_account_id' => $account,
        ];
    }
}
