<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\StatutoryFinancials\Models\StatutoryDossier;

/**
 * Tenant, user, paging and message lookup of the request for the statutory financial statements controllers.
 */
trait InteractsWithStatutoryFinancials
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? request()->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    private function userId(): string
    {
        return (string) request()->user()->getKey();
    }

    private function perPage(int $default = 25): int
    {
        return max(1, min(100, (int) request()->query('per_page', $default)));
    }

    private function message(string $key): string
    {
        return __('statutory_financials.messages.'.$key);
    }

    private function dossier(string $id): StatutoryDossier
    {
        return StatutoryDossier::query()->findOrFail($id);
    }

    /**
     * @param  class-string<JsonResource>  $resource
     * @param  LengthAwarePaginator<int, *>  $page
     */
    private function paged(LengthAwarePaginator $page, string $resource): JsonResponse
    {
        return $this->successResponse($resource::collection($page->items()), $this->message('listed'), 200, [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }
}
