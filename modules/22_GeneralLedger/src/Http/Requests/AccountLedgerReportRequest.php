<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Enums\LedgerBook;

/**
 * Account, range, page and optional dimension filters of the account ledger (mastrino).
 */
final class AccountLedgerReportRequest extends FormRequest
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
            'account_id' => ['required', 'uuid'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantOwns('cost_centers')],
            'profit_center_id' => ['nullable', 'uuid', $this->tenantOwns('profit_centers')],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'ledger' => ['nullable', 'string', Rule::in(LedgerBook::accepted())],
        ];
    }

    /**
     * @return array{cost_center_id?: ?string, profit_center_id?: ?string, project_id?: ?string}
     */
    public function dimensions(): array
    {
        return array_filter([
            'cost_center_id' => $this->validated('cost_center_id'),
            'profit_center_id' => $this->validated('profit_center_id'),
            'project_id' => $this->validated('project_id'),
        ]);
    }

    /**
     * The ledger type of the requested book (`01` statutory, `02` tax, `03` ifrs); statutory when not asked.
     */
    public function ledgerType(): string
    {
        $ledger = $this->validated('ledger');

        return LedgerBook::resolve(is_string($ledger) ? $ledger : null)->ledgerType();
    }
}
