<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Models\RecurringJournal;

/**
 * A recurring journal template: frequency, first due date, optional end and balanced lines.
 */
final class StoreRecurringJournalRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
            'description' => ['required', 'string', 'max:450'],
            'frequency' => ['required', Rule::in(RecurringJournal::Frequencies)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'reverse_next_day' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'uuid', $this->tenantOwns('chart_of_accounts')],
            'lines.*.debit_amount' => ['required_without:lines.*.credit_amount', 'nullable', 'numeric', 'min:0'],
            'lines.*.credit_amount' => ['required_without:lines.*.debit_amount', 'nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.cost_center_id' => ['nullable', 'uuid', $this->tenantOwns('cost_centers')],
            'lines.*.profit_center_id' => ['nullable', 'uuid', $this->tenantOwns('profit_centers')],
        ];
    }
}
