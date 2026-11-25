<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\CustomerHierarchyRole;
use Modules\SalesBoundedContext\Domain\Enums\InsolvencyRiskStatus;

final class UpsertCustomerDossierRequest extends FormRequest
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
            'parent_dossier_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'customer_code' => ['nullable', 'string', 'max:40'],
            'company_name' => ['required', 'string', 'max:180'],
            'hierarchy_role' => ['nullable', Rule::in(CustomerHierarchyRole::values())],
            'country' => ['required', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'uid_number' => ['nullable', 'string', 'max:32'],
            'vat_number' => ['nullable', 'string', 'max:32'],
            'fiscal_code' => ['nullable', 'string', 'max:32'],
            'sdi_code' => ['nullable', 'string', 'max:16'],
            'pec_email' => ['nullable', 'email', 'max:180'],
            'qr_iban' => ['nullable', 'string', 'max:34'],
            'addresses' => ['nullable', 'array'],
            'contacts' => ['nullable', 'array'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'current_exposure' => ['nullable', 'numeric', 'min:0'],
            'overdue_balance' => ['nullable', 'numeric', 'min:0'],
            'insolvency_status' => ['nullable', Rule::in(InsolvencyRiskStatus::values())],
            'risk_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
