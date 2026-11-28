<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Exists;
use Modules\Beekeeping\Models\Apiary;

/**
 * Base of the form requests of the module: writing needs the `manage` ability; tenant-scoped `exists` rules.
 */
abstract class BeekeepingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Apiary::class);
    }

    protected function tenantId(): ?string
    {
        return app(TenantContext::class)->getTenantId();
    }

    protected function existsInTenant(string $table): Exists
    {
        return (new Exists($table, 'id'))->where('tenant_id', $this->tenantId())->whereNull('deleted_at');
    }
}
