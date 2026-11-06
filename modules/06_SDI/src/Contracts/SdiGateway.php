<?php

declare(strict_types=1);

namespace Modules\SDI\Contracts;

use App\Exceptions\SdiProviderException;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Modules\SDI\DTOs\SdiInboundItem;

interface SdiGateway
{
    /**
     * Stable identifier stored on logs and UUID links.
     */
    public function name(): string;

    /**
     * Hand a FatturaPA file over to SDI.
     *
     * @return array{uuid: string, response: array<string, mixed>}
     *
     * @throws SdiProviderException when the channel refuses or cannot process the file
     */
    public function send(Tenant $tenant, string $fileName, string $xml): array;

    /**
     * Read the receipts and supplier invoices waiting on the channel.
     *
     * @return list<SdiInboundItem>
     *
     * @throws SdiProviderException when the channel cannot be reached
     */
    public function poll(Tenant $tenant): array;

    /**
     * Remember that an item was handled, so the next poll does not read it again.
     */
    public function acknowledge(Tenant $tenant, SdiInboundItem $item): void;

    /**
     * Whether an incoming webhook call really comes from this channel.
     */
    public function verifyWebhook(Tenant $tenant, Request $request): bool;
}
