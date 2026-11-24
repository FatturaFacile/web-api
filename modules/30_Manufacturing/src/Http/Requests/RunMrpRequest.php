<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

/**
 * Validation of an MRP run: horizon of at most two years, optional items.
 */
final class RunMrpRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'horizon_start_date' => ['required', 'date'],
            'horizon_end_date' => ['required', 'date', 'after_or_equal:horizon_start_date', 'before_or_equal:'.$this->latestEnd()],
            'run_type' => ['nullable', 'string', 'in:regenerative,net_change'],
            'item_ids' => ['nullable', 'array', 'max:5000'],
            'item_ids.*' => ['uuid', 'distinct', $this->tenantOwns('mfg_items')],
        ];
    }

    /**
     * Two years after the horizon start (the start itself when it cannot be read).
     */
    private function latestEnd(): string
    {
        $start = strtotime((string) $this->input('horizon_start_date'));

        return date('Y-m-d', strtotime('+2 years', $start !== false ? $start : time()));
    }
}
