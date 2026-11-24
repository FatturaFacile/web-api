<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;

/**
 * Tenant and pagination of the request for the credit management controllers.
 */
trait InteractsWithCreditManagement
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? request()->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    private function userId(): ?string
    {
        $id = request()->user()?->getKey();

        return $id === null ? null : (string) $id;
    }

    private function perPage(): int
    {
        return max(1, min(100, (int) request()->query('per_page', 25)));
    }

    /**
     * Sets the customer_name attribute of records that carry a customer_id, for the resources.
     *
     * @param  iterable<int, Model>  $records
     */
    private function withCustomerNames(iterable $records): void
    {
        $ids = [];

        foreach ($records as $record) {
            $ids[] = (string) $record->getAttribute('customer_id');
        }

        $names = app(ReceivablesLookupInterface::class)->customerNames($this->tenantId(), $ids);

        foreach ($records as $record) {
            $record->setAttribute('customer_name', $names[(string) $record->getAttribute('customer_id')] ?? null);
        }
    }
}
