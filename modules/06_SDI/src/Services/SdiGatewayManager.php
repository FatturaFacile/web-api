<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Exceptions\SdiProviderException;
use App\Models\Tenant;
use Modules\SDI\Contracts\SdiGateway;
use Modules\SDI\Enums\SdiChannel;
use Modules\SDI\Gateways\AcubeGateway;
use Modules\SDI\Gateways\InvoicetronicGateway;
use Modules\SDI\Gateways\LocalGateway;
use Modules\SDI\Gateways\OpenapiGateway;
use Modules\SDI\Gateways\PecGateway;

/**
 * Finds the SDI channel of a tenant. Each company of an installation can use a different one.
 */
final class SdiGatewayManager
{
    public function channelFor(?Tenant $tenant): ?SdiChannel
    {
        return SdiChannel::tryFrom((string) ($tenant?->sdi_channel ?: config('services.sdi.default_channel')));
    }

    /**
     * @throws SdiProviderException when the tenant has not chosen a channel
     */
    public function for(?Tenant $tenant): SdiGateway
    {
        $channel = $this->channelFor($tenant);

        if ($channel === null) {
            throw new SdiProviderException(__('sdi.channel_not_configured'));
        }

        return app(match ($channel) {
            SdiChannel::Pec => PecGateway::class,
            SdiChannel::Openapi => OpenapiGateway::class,
            SdiChannel::Acube => AcubeGateway::class,
            SdiChannel::Invoicetronic => InvoicetronicGateway::class,
            SdiChannel::Local => LocalGateway::class,
        });
    }
}
