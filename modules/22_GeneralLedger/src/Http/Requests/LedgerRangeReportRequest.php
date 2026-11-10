<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A range of posting dates, an optional comparison range and optional dimension filters of a ledger report.
 */
final class LedgerRangeReportRequest extends FormRequest
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
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'compare_from' => ['nullable', 'required_with:compare_to', 'date'],
            'compare_to' => ['nullable', 'required_with:compare_from', 'date', 'after_or_equal:compare_from'],
            'cost_center_id' => ['nullable', 'uuid', $this->tenantOwns('cost_centers')],
            'profit_center_id' => ['nullable', 'uuid', $this->tenantOwns('profit_centers')],
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'presentation_currency' => ['nullable', 'string', 'size:3'],
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
}
