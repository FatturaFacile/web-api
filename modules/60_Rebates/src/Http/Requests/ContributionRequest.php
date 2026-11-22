<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Rebates\Models\RebateContribution;

/**
 * A marketing, co-marketing or listing contribution planned on a supplier rebate agreement.
 */
final class ContributionRequest extends RebatesFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'kind' => ['required', Rule::in(RebateContribution::Kinds)],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'due_on' => ['required', 'date_format:Y-m-d'],
        ]);
    }
}
