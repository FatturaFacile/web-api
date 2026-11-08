<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Models\BankReconciliationRule;
use App\Models\BankStatementLine;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use OpenApi\Attributes as OA;

/**
 * A reconciliation rule. At least one condition is required, an incoming rule can only produce internal transfers and
 * the account, when given, must be in the chart of accounts of the tenant. On update every field is optional.
 */
#[OA\Schema(
    schema: 'BankingReconciliationRuleRequest',
    required: ['name', 'category'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 120),
        new OA\Property(property: 'priority', description: 'Lower runs first', type: 'integer', minimum: 1, maximum: 100000),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'direction', type: 'string', enum: ['credit', 'debit'], nullable: true),
        new OA\Property(property: 'expected_amount', type: 'number', nullable: true),
        new OA\Property(property: 'amount_tolerance', description: 'Accepted difference from expected_amount', type: 'number', minimum: 0),
        new OA\Property(property: 'min_amount', type: 'number', nullable: true),
        new OA\Property(property: 'max_amount', type: 'number', nullable: true),
        new OA\Property(property: 'reference_contains', type: 'string', nullable: true),
        new OA\Property(property: 'description_contains', type: 'string', nullable: true),
        new OA\Property(property: 'counterparty_iban', type: 'string', nullable: true),
        new OA\Property(property: 'counterparty_name_contains', type: 'string', nullable: true),
        new OA\Property(property: 'category', type: 'string', enum: BankStatementLine::NON_INVOICE_CATEGORIES),
        new OA\Property(property: 'ledger_account_code', description: 'Counter account; default: the account configured for the category', type: 'string', nullable: true),
    ]
)]
class StoreReconciliationRuleRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * A rule of another company, or no rule at all, is not found before any validation runs.
     */
    protected function prepareForValidation(): void
    {
        if ($this->route('ruleId') !== null) {
            BankReconciliationRule::query()->findOrFail($this->route('ruleId'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId();
        $ruleId = $this->route('ruleId');

        return [
            'name' => [$required, 'string', 'max:120', Rule::unique('bank_reconciliation_rules', 'name')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($ruleId)],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
            'direction' => ['nullable', Rule::in([BankStatementLine::CREDIT, BankStatementLine::DEBIT])],
            'expected_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'amount_tolerance' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'min_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'max_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'gte:min_amount'],
            'reference_contains' => ['nullable', 'string', 'max:140'],
            'description_contains' => ['nullable', 'string', 'max:140'],
            'counterparty_iban' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}\d{2}[A-Za-z0-9 ]{11,40}$/'],
            'counterparty_name_contains' => ['nullable', 'string', 'max:140'],
            'category' => [$required, 'string', Rule::in(BankStatementLine::NON_INVOICE_CATEGORIES)],
            'ledger_account_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.\-]{1,20}$/', $this->tenantOwns('chart_of_accounts', 'account_code')],
        ];
    }

    /**
     * A rule must have a condition (on create, or when the update would leave none), and an incoming rule can only
     * categorise internal transfers.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $current = $this->route('ruleId') === null ? null : BankReconciliationRule::query()->find($this->route('ruleId'));
                $merged = array_merge($current?->only([...BankReconciliationRule::CONDITIONS, 'direction', 'category']) ?? [], $this->validated());
                $hasCondition = collect(BankReconciliationRule::CONDITIONS)->contains(fn (string $field): bool => ($merged[$field] ?? null) !== null && $merged[$field] !== '');

                if (! $hasCondition) {
                    $validator->errors()->add('conditions', 'A rule needs at least one condition: amount, reference, description or counterparty.');
                }

                if (($merged['direction'] ?? null) === BankStatementLine::CREDIT && ($merged['category'] ?? null) !== BankStatementLine::CATEGORY_INTERNAL_TRANSFER) {
                    $validator->errors()->add('category', __('banking.errors.category_not_for_credit'));
                }
            },
        ];
    }
}
