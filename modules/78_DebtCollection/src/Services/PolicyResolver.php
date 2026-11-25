<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Modules\DebtCollection\Models\Policy;

/**
 * Picks the dunning policy of a customer: the policy written for that customer, else the one of its currency, else the
 * default of the jurisdiction. Loaded once per run.
 */
final class PolicyResolver
{
    /** @var array<string, Collection<int, Policy>> by tenant */
    private array $policies = [];

    public function __construct(private readonly DefaultPolicyProvisioner $defaults, private readonly TenantContext $tenantContext) {}

    public function resolve(string $jurisdiction, string $currency, string $customerId): ?Policy
    {
        $candidates = $this->all()->filter(static fn (Policy $policy): bool => $policy->is_active && $policy->jurisdiction === $jurisdiction);

        $scored = $candidates
            ->filter(static fn (Policy $policy): bool => ($policy->customer_id === null || $policy->customer_id === $customerId) && ($policy->currency === null || $policy->currency === $currency))
            ->sortByDesc(static fn (Policy $policy): int => ($policy->customer_id === null ? 0 : 4) + ($policy->currency === null ? 0 : 2) + ($policy->is_default ? 1 : 0));

        return $scored->first();
    }

    /**
     * @return Collection<int, Policy>
     */
    private function all(): Collection
    {
        $key = (string) $this->tenantContext->getTenantId();

        if (! isset($this->policies[$key])) {
            $this->defaults->ensure();
            $this->policies[$key] = Policy::query()->with(['levels' => static fn ($query) => $query->where('is_active', true)->orderBy('level')])->get();
        }

        return $this->policies[$key];
    }

    public function flush(): void
    {
        $this->policies = [];
    }
}
