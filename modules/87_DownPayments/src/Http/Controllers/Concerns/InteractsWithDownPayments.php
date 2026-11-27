<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\DownPayments\Models\DownPaymentInstallment;
use Modules\DownPayments\Models\DownPaymentLink;
use Modules\DownPayments\Models\EarnestAndSecurityDeposit;

/**
 * Tenant, user, message lookup and the tenant-scoped loading of the records of a chain for the down-payment controllers.
 */
trait InteractsWithDownPayments
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
        return max(1, min(200, (int) request()->query('per_page', $default)));
    }

    private function message(string $key): string
    {
        return __('down_payments.messages.'.$key);
    }

    private function chain(string $id): DownPaymentChain
    {
        return DownPaymentChain::query()->where('tenant_id', $this->tenantId())->whereKey($id)->firstOrFail();
    }

    private function installment(DownPaymentChain $chain, string $id): DownPaymentInstallment
    {
        return DownPaymentInstallment::query()->where('tenant_id', $chain->tenant_id)->where('chain_id', $chain->id)->whereKey($id)->firstOrFail();
    }

    private function link(DownPaymentChain $chain, string $id): DownPaymentLink
    {
        return DownPaymentLink::query()->with('vatLines')->where('tenant_id', $chain->tenant_id)->where('chain_id', $chain->id)->whereKey($id)->firstOrFail();
    }

    private function deposit(DownPaymentChain $chain, string $id): EarnestAndSecurityDeposit
    {
        return EarnestAndSecurityDeposit::query()->where('tenant_id', $chain->tenant_id)->where('chain_id', $chain->id)->whereKey($id)->firstOrFail();
    }
}
