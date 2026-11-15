<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Contracts\Models\ContractTemplate;

/**
 * A contract template: declared variables and the ordered clauses of the library.
 */
final class SaveTemplateRequest extends AuthoringFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'name' => ['required', 'string', 'max:255'],
            'contract_kind' => ['nullable', Rule::in(['customer', 'supplier'])],
            'status' => ['sometimes', Rule::in(ContractTemplate::Statuses)],
            'variables' => ['nullable', 'array', 'max:100'],
            'variables.*.key' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]+$/', 'max:60', 'distinct'],
            'variables.*.label' => ['nullable', 'string', 'max:120'],
            'variables.*.required' => ['sometimes', 'boolean'],
            'variables.*.default' => ['nullable', 'string', 'max:500'],
            'clauses' => ['required', 'array', 'min:1', 'max:200'],
            'clauses.*.clause_id' => ['required', 'uuid', $this->ownRow('contract_clauses')],
            'clauses.*.is_optional' => ['sometimes', 'boolean'],
        ]);
    }
}
