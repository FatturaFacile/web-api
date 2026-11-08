<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Services\PaymentProposalService;
use OpenApi\Attributes as OA;

/**
 * The selection criteria of a supplier payment proposal.
 */
#[OA\Schema(
    schema: 'TreasuryStorePaymentProposalRequest',
    required: ['bank_account_id', 'execution_date', 'due_until', 'format'],
    properties: [
        new OA\Property(property: 'bank_account_id', description: 'Bank account to debit; its currency selects the invoices', type: 'string', format: 'uuid'),
        new OA\Property(property: 'execution_date', type: 'string', format: 'date'),
        new OA\Property(property: 'due_until', description: 'Invoices due on or before this day are proposed', type: 'string', format: 'date'),
        new OA\Property(property: 'include_discounts', description: 'Also propose invoices whose early-payment discount is still available, net of the discount', type: 'boolean', default: true),
        new OA\Property(property: 'format', type: 'string', enum: ['sepa_pain_001_it', 'iso_pain_001_ch']),
        new OA\Property(property: 'supplier_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
    ]
)]
final class StorePaymentProposalRequest extends FormRequest
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
            'bank_account_id' => ['required', 'uuid', $this->tenantOwns('bank_accounts')],
            'execution_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'due_until' => ['required', 'date_format:Y-m-d'],
            'include_discounts' => ['sometimes', 'boolean'],
            'format' => ['required', Rule::in(array_keys(PaymentProposalService::Formats))],
            'supplier_ids' => ['nullable', 'array', 'max:500'],
            'supplier_ids.*' => ['uuid', $this->tenantOwns('contacts')],
        ];
    }
}
