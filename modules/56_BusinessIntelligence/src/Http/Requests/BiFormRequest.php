<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared behaviour of the business intelligence payloads: the access decision lives in the module.access middleware and
 * the policy, a PUT only validates the fields it sends, and ids must belong to the active company.
 */
abstract class BiFormRequest extends FormRequest
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

    /**
     * Rules of a pivot layout.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function pivotRules(string $prefix = 'pivot'): array
    {
        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.rows" => ['nullable', 'array', 'max:4'],
            "{$prefix}.rows.*" => ['string', 'max:41'],
            "{$prefix}.columns" => ['nullable', 'array', 'max:2'],
            "{$prefix}.columns.*" => ['string', 'max:41'],
            "{$prefix}.values" => ['nullable', 'array', 'max:8'],
            "{$prefix}.values.*" => ['string', 'max:41'],
        ];
    }
}
