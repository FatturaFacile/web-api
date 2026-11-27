<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tenant, user, paging and message lookup of the request for the foreign VAT refund controllers.
 */
trait InteractsWithForeignVat
{
    private function tenantId(): string
    {
        return (string) app(TenantContext::class)->getTenantId();
    }

    private function userId(): ?string
    {
        $id = request()->user()?->getKey();

        return $id === null ? null : (string) $id;
    }

    private function perPage(int $default = 25): int
    {
        return max(1, min(100, (int) request()->query('per_page', $default)));
    }

    private function message(string $key): string
    {
        return __('foreign_vat_refund.messages.'.$key);
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
