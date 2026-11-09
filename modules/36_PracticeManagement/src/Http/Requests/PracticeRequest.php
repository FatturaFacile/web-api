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
                'activity_ids' => ['required', 'array', 'min:1', 'max:500'],
                'activity_ids.*' => ['uuid', 'distinct'],
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
            default => [],
        };
    }
}
