<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\AnalyticalAccounting\Domain\Enums\CostElementType;

/**
 * Validation of a manual analytical adjustment: lines with optional allocation vectors. The statutory ledger is never
 * touched by this request.
 */
final class EntryRequest extends AnalyticalFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $dimension = ['nullable', 'uuid', $this->owned('analytical_centers')];
        $element = ['nullable', Rule::in(CostElementType::values())];

        return [
            'posting_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'gl_journal_entry_id' => ['nullable', 'uuid'],
            'invoice_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.amount' => ['required', 'numeric'],
            'lines.*.cost_element_type' => $element,
            'lines.*.cost_center_id' => $dimension,
            'lines.*.profit_center_id' => $dimension,
            'lines.*.project_id' => ['nullable', 'uuid'],
            'lines.*.product_line_id' => ['nullable', 'uuid'],
            'lines.*.account_id' => ['nullable', 'uuid'],
            'lines.*.accounting_cause_id' => ['nullable', 'uuid'],
            'lines.*.product_id' => ['nullable', 'uuid'],
            'lines.*.product_category_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.vectors' => ['nullable', 'array', 'min:1'],
            'lines.*.vectors.*.cost_center_id' => $dimension,
            'lines.*.vectors.*.profit_center_id' => $dimension,
            'lines.*.vectors.*.project_id' => ['nullable', 'uuid'],
            'lines.*.vectors.*.product_line_id' => ['nullable', 'uuid'],
            'lines.*.vectors.*.cost_element_type' => $element,
            'lines.*.vectors.*.percentage' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'lines.*.vectors.*.amount' => ['nullable', 'numeric'],
        ];
    }
}
