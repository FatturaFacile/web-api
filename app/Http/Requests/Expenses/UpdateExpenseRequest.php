<?php

declare(strict_types=1);

namespace App\Http\Requests\Expenses;

use App\Models\LookupCategory;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
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
                'sometimes',
                'required',
                'uuid',
                Rule::exists('lookup_items', 'id')->where(function ($query) use ($expenseCategoryIds): void {
                    $query->whereIn('category_id', $expenseCategoryIds);
                }),
            ],
            'date' => ['sometimes', 'required', 'date'],
            'amount_gross' => ['sometimes', 'nullable', 'numeric', 'min:0.01'],
            'amount_net' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'merchant_name' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['sometimes', 'string', 'in:cash,credit_card,company_card,bank_transfer,reimbursement,other'],
            'status' => ['sometimes', 'string', 'in:draft,submitted,approved,rejected'],
            'is_billable' => ['sometimes', 'boolean'],
            'is_reimbursable' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'contact_id' => [
                'nullable',
                'uuid',
                Rule::exists('contacts', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'user_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id'),
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
        ];
    }
}
