<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\CreditManagement\Models\CreditDunningStep;

/**
 * Payload of a dunning strategy with its ordered steps.
 */
final class DunningStrategyRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.name' => ['required', 'string', 'max:120'],
            'steps.*.days_after_due' => ['required', 'integer', 'min:0', 'max:3650'],
            'steps.*.channel' => ['required', Rule::in(CreditDunningStep::Channels)],
            'steps.*.template_key' => ['nullable', 'string', 'max:120'],
            'steps.*.fee_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'steps.*.escalates' => ['boolean'],
        ]);
    }
}
