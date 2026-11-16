<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Contracts\Models\ContractNegotiationRound;

/**
 * Closes the open negotiation round with an outcome.
 */
final class CloseRoundRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['outcome' => ['required', Rule::in(ContractNegotiationRound::Outcomes)], 'summary' => ['nullable', 'string', 'max:2000']];
    }
}
