<?php

declare(strict_types=1);

namespace Modules\DataResidency\Presentation\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\DataResidency\Domain\Enums\KeyEnvelopeStatus;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Modules\DataResidency\Infrastructure\Services\ResidencyPolicyResolver;
use Modules\DataResidency\Infrastructure\Services\ResidencyRuntime;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the residency context of the active tenant and legal entity (X-Company-Id) to the request and answers 423 Locked
 * as soon as the customer-managed key of that tenant or entity is suspended or crypto-shredded. The sovereignty API itself
 * stays reachable, so the CISO can inspect, rotate and audit while the data is locked.
 */
final class EnforceDataResidencyMiddleware
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResidencyPolicyResolver $policies,
        private readonly ResidencyRuntime $runtime,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($tenantId === null || $request->is('api/v1/sovereignty/*')) {
            return $next($request);
        }

        $companyId = $request->header('X-Company-Id');
        $entityId = is_string($companyId) && $companyId !== '' ? $companyId : null;

        $locked = CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [KeyEnvelopeStatus::Suspended->value, KeyEnvelopeStatus::RevokedCryptoShredded->value])
            ->where(static fn ($query) => $entityId === null ? $query->whereNull('legal_entity_id') : $query->whereNull('legal_entity_id')->orWhere('legal_entity_id', $entityId))
            ->first();

        if ($locked !== null) {
            return $this->problemDetailsResponse('Locked: Key Access Suspended', 'The customer-managed key of this tenant is '.$locked->status->value.': encrypted data is locked.', 423, null, null, [['name' => 'code', 'reason' => 'TENANT_CRYPTO_SHREDDED_OR_SUSPENDED']]);
        }

        $this->runtime->bind($tenantId, $entityId, $this->policies->resolve($tenantId, $entityId));

        return $next($request);
    }
}
