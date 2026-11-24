<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Tenant, user and date helpers for the facility controllers.
 */
trait InteractsWithFacility
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? request()->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    private function actor(): User
    {
        $user = request()->user();
        abort_if($user === null, 401);

        return $user;
    }

    private function actorId(): string
    {
        return (string) $this->actor()->getKey();
    }

    private function perPage(): int
    {
        return max(1, min(100, (int) request()->query('per_page', 25)));
    }

    /**
     * Start of a company business day (`Y-m-d`) as a UTC instant.
     */
    private function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, app(TenantClock::class)->timezone())->startOfDay()->utc();
    }

    private function parseInstant(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->utc();
    }
}
