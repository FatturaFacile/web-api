<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared behaviour of the talent and learning payloads: the access decision lives in the module.access middleware and
 * the policy, a PUT only validates the fields it sends, and ids must belong to the active company.
 */
abstract class TalentFormRequest extends FormRequest
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

    /**
     * A row of the table that belongs to the active company.
     */
    protected function ownRow(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('tenant_id', (string) app(TenantContext::class)->getTenantId())
            ->whereNull('deleted_at');
    }
}
