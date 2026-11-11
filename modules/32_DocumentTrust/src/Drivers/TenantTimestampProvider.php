<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Drivers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Modules\DocumentTrust\Contracts\TrustedTimestampProvider;
use Modules\DocumentTrust\Models\PreservationSetting;
use Modules\DocumentTrust\Support\TimestampToken;

/**
 * Chooses the time-stamp provider of the active tenant at the moment of use: its RFC 3161 authority when one is
 * configured (and then never silently replaced by the local clock when it fails), otherwise the provider named in
 * `services.preservation.timestamp_provider` (`local` or the class name of an own provider).
 */
final readonly class TenantTimestampProvider implements TrustedTimestampProvider
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantContext $tenantContext, private Container $container) {}

    /**
     * Name of the provider that would be used now.
     */
    public function name(): string
    {
        return $this->delegate()->name();
    }

    /**
     * Time-stamp with the provider of the tenant.
     */
    public function timestamp(string $sha256Hex): TimestampToken
    {
        return $this->delegate()->timestamp($sha256Hex);
    }

    private function delegate(): TrustedTimestampProvider
    {
        $url = PreservationSetting::where('tenant_id', $this->tenantContext->getTenantId())->value('tsa_url');

        if ($url !== null && $url !== '') {
            return $this->container->make(Rfc3161TimestampProvider::class);
        }

        $configured = (string) config('services.preservation.timestamp_provider', 'local');

        return $configured !== 'local' && is_a($configured, TrustedTimestampProvider::class, true) && $configured !== self::class
            ? $this->container->make($configured)
            : $this->container->make(LocalClockTimestampProvider::class);
    }
}
