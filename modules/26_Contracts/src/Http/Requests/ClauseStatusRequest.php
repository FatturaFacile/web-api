<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Contracts\Models\ContractClause;

/**
 * Moves a clause between draft, approved and retired.
 */
final class ClauseStatusRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(ContractClause::Statuses)]];
    }
}
