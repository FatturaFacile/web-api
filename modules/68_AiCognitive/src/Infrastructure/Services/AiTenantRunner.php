<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;

/**
 * Runs a handler or a job inside the tenant context, so the tenant scope of every model applies.
 */
final readonly class AiTenantRunner
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     *
     * @throws AiCognitiveException when the tenant does not exist
     */
    public function run(string $tenantId, callable $callback): mixed
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            throw new AiCognitiveException('The tenant does not exist.');
        }

        return $this->tenantContext->runFor($tenant, static fn (): mixed => $callback($tenant));
    }
}
