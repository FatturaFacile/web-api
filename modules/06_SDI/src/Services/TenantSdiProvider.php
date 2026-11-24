<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Contracts\SdiProvider;
use App\Exceptions\SdiProviderException;
use App\Services\Tenancy\TenantContext;

/**
 * The SDI provider of the active tenant: invoicing code keeps a single contract while each company picks its channel.
 */
final class TenantSdiProvider implements SdiProvider
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SdiGatewayManager $gateways,
        private readonly FatturaPaSchemaValidator $schema,
    ) {}

    public function name(): string
    {
        return $this->gateways->channelFor($this->tenantContext->getTenant())->value ?? 'unconfigured';
    }

    public function send(string $fileName, string $xml): array
    {
        $tenant = $this->tenantContext->getTenant();
        $gateway = $this->gateways->for($tenant);

        if ($this->gateways->channelFor($tenant)?->isReal()) {
            $errors = $this->schema->errors($xml);

            if ($errors !== []) {
                throw new SdiProviderException(__('sdi.invalid_schema', ['errors' => implode(' | ', array_slice($errors, 0, 5))]));
            }
        }

        return $gateway->send($tenant, $fileName, $xml);
    }
}
