<?php

declare(strict_types=1);

namespace Modules\SDI\Gateways;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Modules\SDI\Contracts\SdiGateway;
use Modules\SDI\DTOs\SdiInboundItem;

/**
 * Manual channel: the file is generated and kept, the company uploads it to the AdE portal (or its own intermediary).
 */
final class ManualGateway implements SdiGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function send(Tenant $tenant, string $fileName, string $xml): array
    {
        return [
            'uuid' => preg_replace('/\.xml(\.p7m)?$/i', '', $fileName) ?? $fileName,
            'response' => ['manual' => true, 'file_name' => $fileName],
        ];
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
