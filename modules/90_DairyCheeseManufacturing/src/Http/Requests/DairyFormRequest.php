<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Modules\DairyCheeseManufacturing\Models\Breeder;

/**
 * Base of the requests of the module: the policy ability the request needs and tenant-scoped exists / unique rules.
 */
abstract class DairyFormRequest extends FormRequest
{
    /**
     * viewAny, manage, settle or regulatory (DairyCheesePolicy).
     */
    protected function ability(): string
    {
        return 'manage';
    }

    public function authorize(): bool
    {
        return Gate::allows($this->ability(), Breeder::class);
    }

    protected function tenantId(): ?string
    {
        return app(TenantContext::class)->getTenantId();
    }

    protected function existsIn(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at');
    }

    protected function uniqueIn(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->ignore($this->route('id'));
    }
}
