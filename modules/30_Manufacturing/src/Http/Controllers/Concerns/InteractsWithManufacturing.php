<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Helpers of the manufacturing controllers: the active company (set by the tenant middleware, never guessed), records
 * of that company only (404 otherwise), the acting user and the page size.
 */
trait InteractsWithManufacturing
{
    /**
     * The active company.
     */
    protected function tenantId(): string
    {
        return (string) app(TenantContext::class)->getTenantId();
    }

    /**
     * The record of the active company, 404 otherwise.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    protected function owned(string $model, string $id): Model
    {
        /** @var TModel $record */
        $record = $model::query()->where('tenant_id', $this->tenantId())->findOrFail($id);

        return $record;
    }

    /**
     * The authenticated user id.
     */
    protected function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }

    /**
     * Rows per page, between 1 and 100.
     */
    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', '25')));
    }
}
