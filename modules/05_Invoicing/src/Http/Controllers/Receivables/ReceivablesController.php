<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers\Receivables;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Modules\Invoicing\Models\CustomerCreditProfile;

/**
 * Shared plumbing of the receivables controllers: the active company, its calendar, the acting user and the check that a
 * customer belongs to the company (a customer of another company answers 404, like any other record of another company).
 */
abstract class ReceivablesController extends Controller
{
    public function __construct(protected readonly TenantContext $tenantContext, protected readonly TenantClock $clock) {}

    protected function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    protected function today(Request $request): CarbonImmutable
    {
        $today = $this->clock->today(Tenant::query()->find($this->tenantId()));
        $request->attributes->set('receivables_today', $today);

        return $today;
    }

    protected function userId(Request $request): ?string
    {
        return $request->user()?->id !== null ? (string) $request->user()->id : null;
    }

    protected function ensureCustomer(string $customerId): void
    {
        abort_unless(Contact::withoutGlobalScopes()->where('id', $customerId)->where('tenant_id', $this->tenantId())->exists(), 404);
    }

    /**
     * Only the credit managers of the company (see CustomerCreditProfilePolicy) change credit data.
     */
    protected function authorizeManage(): void
    {
        $this->authorize('manageCredit', CustomerCreditProfile::class);
    }
}
