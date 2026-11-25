<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait ResolvesDebtCollectionContext
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    /**
     * The calculation date: the one asked for, otherwise today in the time zone of the company.
     */
    private function asOf(?string $requested): string
    {
        return $requested ?? app(TenantClock::class)->today()->toDateString();
    }

    /**
     * @template TModel of Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $page
     * @return array<string, int>
     */
    private function pageMeta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
