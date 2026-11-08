<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Gateways;

use App\Models\Tenant;
use Illuminate\Support\Str;
use Modules\EDocumentNetworks\Contracts\EDocumentGateway;
use Modules\EDocumentNetworks\DTOs\EDocumentEnvelope;
use Modules\EDocumentNetworks\Enums\Network;
use Modules\EDocumentNetworks\Exceptions\NetworkNotAvailableException;

/**
 * Simulator for development and tests, one per network. Nothing leaves the installation and it refuses to run in
 * production, where an accepted transmission must mean a real delivery.
 */
final readonly class LocalGateway implements EDocumentGateway
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private Network $network) {}

    /**
     * Driver or resolver name.
     */
    public function name(): string
    {
        return "local-{$this->network->value}";
    }

    /**
     * Whether the driver can run.
     */
    public function isAvailable(): bool
    {
        return ! app()->isProduction();
    }

    /**
     * Send the message.
     */
    public function send(Tenant $tenant, EDocumentEnvelope $envelope): array
    {
        if (! $this->isAvailable()) {
            throw NetworkNotAvailableException::simulatorInProduction($this->network);
        }

        return ['external_id' => 'LOCAL-'.Str::uuid7(), 'status' => 'simulated'];
    }
}
