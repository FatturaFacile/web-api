<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use App\Models\Tenant;
use Modules\Customs\Contracts\CustomsGateway;
use Modules\Customs\Enums\CustomsChannel;
use Modules\Customs\Exceptions\CustomsGatewayException;
use Modules\Customs\Gateways\EdecGateway;
use Modules\Customs\Gateways\LocalCustomsGateway;
use Modules\Customs\Models\CustomsSettings;

/**
 * Finds the customs channel of a tenant. Each company of an installation can use a different one.
 */
final class CustomsGatewayManager
{
    public function channelFor(Tenant $tenant): ?CustomsChannel
    {
        $settings = CustomsSettings::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        return CustomsChannel::tryFrom((string) ($settings->channel ?? config('customs.default_channel')));
    }

    /**
     * @throws CustomsGatewayException when the channel is unknown
     */
    public function for(Tenant $tenant): CustomsGateway
    {
        $channel = $this->channelFor($tenant) ?? throw new CustomsGatewayException('The customs channel is not configured.');

        return app(match ($channel) {
            CustomsChannel::Edec => EdecGateway::class,
            CustomsChannel::Local => LocalCustomsGateway::class,
        });
    }
}
