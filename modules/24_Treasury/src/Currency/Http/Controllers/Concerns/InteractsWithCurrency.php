<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;

/**
 * Tenant, user, paging, messages and error answers of the currency controllers.
 *
 * A refused operation answers `{success: false, message, code, errors}`: the HTTP status of the rule (404, 409, 422), the machine
 * code (`exchange_rate_missing`, `currency_in_use`, ...) and, when a field is to blame, the error under that field.
 */
trait InteractsWithCurrency
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

    private function message(string $key): string
    {
        return __('currency_fx.messages.'.$key);
    }

    /**
     * @param  class-string<JsonResource>  $resource
     * @param  LengthAwarePaginator<int, *>  $page
     */
    private function paged(LengthAwarePaginator $page, string $resource, string $messageKey = 'listed'): JsonResponse
    {
        return $this->successResponse($resource::collection($page->items()), $this->message($messageKey), 200, [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }

    private function failure(CurrencyDomainException $exception): JsonResponse
    {
        return $exception->render();
    }
}
