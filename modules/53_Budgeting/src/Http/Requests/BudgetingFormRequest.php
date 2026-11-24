<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared behaviour of the budgeting payloads: the access decision lives in the module.access middleware and the policy,
 * and a PUT only validates the fields it sends. Accounts, cost centres and projects are checked against the ledger by
 * the services.
 */
abstract class BudgetingFormRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    protected function forMethod(array $rules): array
    {
        return $this->isMethod('PUT') ? array_map(static fn (array $rule): array => ['sometimes', ...$rule], $rules) : $rules;
    }
}
