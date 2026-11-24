<?php

declare(strict_types=1);

namespace Modules\SDI\Gateways;

use App\Models\Tenant;
use App\Services\ItalianSdi\LocalSdiProvider;
use Illuminate\Http\Request;
use Modules\SDI\Contracts\SdiGateway;
use Modules\SDI\DTOs\SdiInboundItem;

/**
 * Offline channel for development and demos: nothing reaches SDI.
 */
final class LocalGateway implements SdiGateway
{
    public function __construct(private readonly LocalSdiProvider $provider) {}

    public function name(): string
    {
        return 'local';
    }

    public function send(Tenant $tenant, string $fileName, string $xml): array
    {
        return $this->provider->send($fileName, $xml);
    }

    public function poll(Tenant $tenant): array
    {
        return [];
    }

    public function acknowledge(Tenant $tenant, SdiInboundItem $item): void {}

    public function verifyWebhook(Tenant $tenant, Request $request): bool
    {
        return false;
    }
}
