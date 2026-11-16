<?php

declare(strict_types=1);

namespace App\Http\Requests\Expenses;

use App\Models\LookupCategory;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
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
        $expenseCategoryIds = LookupCategory::where('code', 'expense_category')->pluck('id');

        return [
            'category_id' => [
                'required',
                'uuid',
                Rule::exists('lookup_items', 'id')->where(function ($query) use ($expenseCategoryIds, $tenantId): void {
                    $query->whereIn('category_id', $expenseCategoryIds)
                        ->where(fn ($scope) => $scope->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
                }),
            ],
            'date' => ['nullable', 'date'],
            'amount_gross' => ['required_without:amount_net', 'nullable', 'numeric', 'min:0.01'],
            'amount_net' => ['required_without:amount_gross', 'nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'merchant_name' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'in:cash,credit_card,company_card,bank_transfer,reimbursement,other'],
            'status' => ['nullable', 'string', 'in:draft,submitted,approved,rejected'],
            'is_billable' => ['nullable', 'boolean'],
            'is_reimbursable' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'contact_id' => [
                'nullable',
                'uuid',
                Rule::exists('contacts', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'user_id' => [
                'nullable',
                'uuid',
                // A member of the active tenant, never a user of another company.
                Rule::exists('tenant_user', 'user_id')->where('tenant_id', $tenantId),
            ],
            'project_id' => ['nullable', 'uuid'],
            'ticket_id' => [
                'nullable',
                'uuid',
                Rule::exists('tickets', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'service_report_id' => [
                'nullable',
                'uuid',
                Rule::exists('service_reports', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'receipt' => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,pdf', 'max:20480'],
            'receipts' => ['nullable', 'array', 'max:10'],
            'receipts.*' => ['file', 'mimes:jpeg,png,jpg,webp,heic,pdf', 'max:20480'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => __('expenses_errors_2.category.invalid'),
            'contact_id.exists' => __('expenses_errors_2.contact.not_in_tenant'),
            'ticket_id.exists' => __('expenses_errors_2.ticket.not_in_tenant'),
            'service_report_id.exists' => __('expenses_errors_2.service_report.not_in_tenant'),
        ];
    }
}
