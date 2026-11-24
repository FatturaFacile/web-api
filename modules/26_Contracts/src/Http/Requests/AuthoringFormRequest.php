<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared behaviour of the contract authoring payloads: access is decided by module.access and the policy, a PUT only
 * validates the fields it sends and ids must belong to the active company.
 */
abstract class AuthoringFormRequest extends FormRequest
{
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

    protected function ownRow(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->whereNull('deleted_at');
    }
}
