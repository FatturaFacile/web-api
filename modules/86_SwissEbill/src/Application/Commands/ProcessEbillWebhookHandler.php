<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Modules\SwissEbill\Application\Services\EbillNotificationProcessor;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Infrastructure\Webhook\EbillWebhookPayloadParser;
use Modules\SwissEbill\Infrastructure\Webhook\WebhookSignatureVerifier;

/**
 * Authenticates, deduplicates and applies the notifications of a gateway webhook. The signature is checked before anything is
 * parsed; a replayed event id is acknowledged without a second effect.
 */
final class ProcessEbillWebhookHandler
{
    public function __construct(
        private readonly EbillSettingRepositoryInterface $settings,
        private readonly WebhookSignatureVerifier $verifier,
        private readonly EbillWebhookPayloadParser $parser,
        private readonly EbillNotificationProcessor $processor,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @return list<array{event_id: string, outcome: string}>
     *
     * @throws EbillException unknown_webhook (404), invalid_signature (401), payload_unreadable (422), unknown_transaction (404)
     */
    public function handle(ProcessEbillWebhookCommand $command): array
    {
        $setting = $this->settings->findByWebhookSlug($command->slug);

        if ($setting === null || ! $setting->ebill_enabled) {
            // The same answer for an unknown slug and a disabled company: the endpoint reveals nothing.
            throw new EbillException('unknown_webhook', 'Unknown webhook endpoint.', 404);
        }

        $this->verifier->verify($setting, $command->request);
        $events = $this->parser->parse($command->body);
        $tenant = Tenant::query()->findOrFail($setting->tenant_id);

        return $this->tenants->runWithTenant($tenant, function () use ($setting, $events): array {
            $results = [];

            foreach ($events as $event) {
                $results[] = $this->processor->process($setting, $event['notification'], EbillEventSource::GatewayWebhook, $event['raw']);
            }

            return $results;
        });
    }
}
