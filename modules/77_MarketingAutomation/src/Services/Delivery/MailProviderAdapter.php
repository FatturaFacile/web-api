<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Delivery;

use Modules\MarketingAutomation\Domain\Enums\MailProvider;
use Modules\MarketingAutomation\Exceptions\MailDeliveryException;

interface MailProviderAdapter
{
    public function provider(): MailProvider;

    /**
     * @param  array<string, mixed>  $config
     * @return array{reference: ?string}
     *
     * @throws MailDeliveryException
     */
    public function send(OutboundEmail $email, array $config): array;

    /**
     * Normalises a provider webhook payload into bounce/complaint events.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{type: string, address: ?string, reference: ?string}> type: hard_bounce | soft_bounce | complaint | delivered
     */
    public function parseWebhook(array $payload): array;
}
