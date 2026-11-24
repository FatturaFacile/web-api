<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared behaviour of the Transportation management (TMS) payloads: the access decision lives in the module.access middleware and the
 * policy, a PUT only validates the fields it sends, and ids must belong to the active company.
 */
abstract class TransportationFormRequest extends FormRequest
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
        $rule = Rule::exists($table, 'id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId());

        return Schema::hasColumn($table, 'deleted_at') ? $rule->whereNull('deleted_at') : $rule;
    }

    /**
     * A value unique inside the active company (and inside the given scope), ignoring the record being updated.
     *
     * @param  list<string>  $scope  Columns that bound the uniqueness, read from the payload or the stored record.
     */
    protected function uniqueIn(string $table, string $column, array $scope = []): Unique
    {
        $rule = Rule::unique($table, $column)
            ->where('tenant_id', (string) app(TenantContext::class)->getTenantId())
            ->whereNull('deleted_at');

        foreach ($scope as $scopeColumn) {
            $rule->where($scopeColumn, $this->valueOf($scopeColumn, $table));
        }

        $id = $this->route('id');

        return $id !== null ? $rule->ignore((string) $id) : $rule;
    }

    /**
     * The value of a field: the one in the payload, else the one stored on the record being updated.
     */
    protected function valueOf(string $field, ?string $table = null): mixed
    {
        if ($this->has($field)) {
            return $this->input($field);
        }

        $id = $this->route('id');

        return $id !== null && $table !== null ? DB::table($table)->where('id', (string) $id)->value($field) : null;
    }
}
