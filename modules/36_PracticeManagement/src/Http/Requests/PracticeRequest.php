<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Activity control, fee notes from activities and period closings. Each route names the payload it validates.
 */
final class PracticeRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rates are always entered: the module applies no default contribution, withholding or VAT rate.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $period = ['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']];
        $tenantId = app(TenantContext::class)->getTenantId();

        return match (str_replace('api.v1.practice.', '', (string) $this->route()?->getName())) {
            'activity-control' => [...$period, 'user_id' => ['nullable', 'uuid', $this->tenantMember()], 'stale_days' => ['nullable', 'integer', 'min:1', 'max:365']],
            'closing-overview' => $period,
            'fee-notes.from-activities' => [
                'client_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
                'activity_ids' => ['required_without:expense_ids', 'array', 'max:500'],
                'activity_ids.*' => ['uuid', 'distinct'],
                'expense_ids' => ['required_without:activity_ids', 'array', 'max:500'],
                'expense_ids.*' => ['uuid', 'distinct', $this->tenantOwns('practice_expenses')],
                'note_number' => ['nullable', 'string', 'max:50', Rule::unique('fee_notes', 'note_number')->where('tenant_id', $tenantId)],
                'date' => ['required', 'date'],
                'cpa_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
                'ritenuta_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
                'vat_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            ],
            'closings.store' => [
                'client_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
                'period_start' => ['required', 'date'],
                'period_end' => ['required', 'date', 'after_or_equal:period_start'],
                'checklist' => ['required', 'array', 'min:1', 'max:50'],
                'checklist.*.key' => ['required', 'string', 'max:50', 'distinct'],
                'checklist.*.label' => ['required', 'string', 'max:200'],
                'checklist.*.done' => ['sometimes', 'boolean'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ],
            'closings.checklist' => [
                'items' => ['required', 'array', 'min:1'],
                'items.*.key' => ['required', 'string', 'max:50'],
                'items.*.done' => ['required', 'boolean'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ],
            'retainers.index' => ['client_id' => ['sometimes', 'uuid', $this->tenantOwns('contacts')], 'status' => ['sometimes', 'string', 'in:open,exhausted,void']],
            'retainers.store' => [
                'client_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
                'legal_case_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('legal_cases')],
                'received_on' => ['required', 'date'],
                'amount' => ['required', 'decimal:0,2', 'gt:0'],
                'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'retainers.apply' => [
                'fee_note_id' => ['required', 'uuid', $this->tenantOwns('fee_notes')],
                'amount' => ['required', 'decimal:0,2', 'gt:0'],
            ],
            'closings.close' => ['allow_unbilled' => ['sometimes', 'boolean'], 'reason' => ['required_if_accepted:allow_unbilled', 'nullable', 'string', 'max:500']],
            'expenses.index' => ['legal_case_id' => ['sometimes', 'uuid', $this->tenantOwns('legal_cases')], 'is_billed' => ['sometimes', 'boolean']],
            'expenses.store' => [
                'legal_case_id' => ['required', 'uuid', $this->tenantOwns('legal_cases')],
                ...$this->expenseRules(),
            ],
            'expenses.update' => $this->expenseRules(),
            'trust.index' => ['client_id' => ['sometimes', 'uuid', $this->tenantOwns('contacts')], 'legal_case_id' => ['sometimes', 'uuid', $this->tenantOwns('legal_cases')]],
            'trust.store' => [
                'type' => ['required', 'string', 'in:deposit,withdrawal,fee_transfer'],
                'client_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
                'legal_case_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('legal_cases')],
                'occurred_on' => ['required', 'date'],
                'amount' => ['required', 'decimal:0,2', 'gt:0'],
                'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
                'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            ],
            'trust.post' => [
                'bank_account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
                'client_funds_account_id' => ['required', 'uuid', $this->tenantOwns('chart_of_accounts')],
                'fee_revenue_account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
            ],
            'trust.reconcile' => ['bank_statement_line_id' => ['required', 'uuid', $this->tenantOwns('bank_statement_lines')]],
            'expenses.receipt.store' => ['receipt' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240']],
            'trust.reverse' => ['description' => ['sometimes', 'nullable', 'string', 'max:255']],
            'conflicts.check' => [
                'name' => ['required', 'string', 'min:2', 'max:190'],
                'role' => ['required', 'string', 'in:client,opposing_party,related_party'],
                'contact_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('contacts')],
            ],
            'conflict-parties.index' => ['legal_case_id' => ['sometimes', 'uuid', $this->tenantOwns('legal_cases')]],
            'conflict-parties.store' => [
                'legal_case_id' => ['required', 'uuid', $this->tenantOwns('legal_cases')],
                'contact_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('contacts')],
                'name' => ['required', 'string', 'max:190'],
                'role' => ['required', 'string', 'in:client,opposing_party,related_party,witness'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'proposals.index' => ['status' => ['sometimes', 'string', 'in:draft,sent,accepted,declined,expired'], 'client_id' => ['sometimes', 'uuid', $this->tenantOwns('contacts')]],
            'proposals.store' => [
                'client_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
                ...$this->proposalRules(true),
            ],
            'proposals.update' => $this->proposalRules(false),
            'proposals.accept' => [
                'conflict_check_id' => ['required', 'uuid', $this->tenantOwns('practice_conflict_checks')],
                'override_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'proposals.decline' => ['notes' => ['sometimes', 'nullable', 'string', 'max:500']],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseRules(): array
    {
        return [
            'expense_date' => ['required', 'date'],
            'category' => ['required', 'string', 'in:court_fee,travel,filing,expert,courier,other'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'is_billable' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The fee basis decides which amounts are required: an hourly proposal needs rate and hours, a fixed one the fee.
     *
     * @return array<string, mixed>
     */
    private function proposalRules(bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';
        $feeType = (string) $this->input('fee_type', '');

        return [
            'title' => [$presence, 'string', 'max:190'],
            'scope' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'fee_type' => [$presence, 'string', 'in:hourly,fixed'],
            'hourly_rate' => [$feeType === 'hourly' ? 'required' : 'sometimes', 'nullable', 'decimal:0,2', 'gt:0'],
            'estimated_hours' => [$feeType === 'hourly' ? 'required' : 'sometimes', 'nullable', 'decimal:0,2', 'gt:0'],
            'fixed_fee' => [$feeType === 'fixed' ? 'required' : 'sometimes', 'nullable', 'decimal:0,2', 'gt:0'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
