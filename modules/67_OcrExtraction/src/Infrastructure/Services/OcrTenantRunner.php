<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionNotFoundException;

/**
 * Runs a handler inside the tenant context of the command, so the tenant scope of every model applies (queue workers and
 * webhooks have no request tenant).
 */
final readonly class OcrTenantRunner
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     *
     * @throws OcrExtractionNotFoundException when the tenant does not exist
     */
    public function run(string $tenantId, callable $callback): mixed
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            throw new OcrExtractionNotFoundException('The tenant does not exist.');
        }

        return $this->tenantContext->runFor($tenant, static fn (): mixed => $callback($tenant));
    }
}
