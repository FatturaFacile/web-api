<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Modules\ExpenseManagement\Models\ExpenseReport;

/**
 * Tenant, user and report lookup of the request for the expense controllers.
 */
trait InteractsWithExpenses
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

    private function perPage(): int
    {
        return max(1, min(100, (int) request()->query('per_page', 25)));
    }

    private function message(string $key): string
    {
        return __('expense_management.messages.'.$key);
    }

    private function findReport(string $id, string $ability): ExpenseReport
    {
        $report = ExpenseReport::query()->findOrFail($id);
        Gate::authorize($ability, $report);

        return $report;
    }

    private function isManager(): bool
    {
        return Gate::allows('isManager', ExpenseReport::class);
    }

    /**
     * The user whose reports the caller may list: nobody is excluded for managers, others see only their own.
     */
    private function restrictToUser(): ?string
    {
        return $this->isManager() ? null : $this->userId();
    }
}
