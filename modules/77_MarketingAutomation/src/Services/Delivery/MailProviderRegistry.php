<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Delivery;

use Modules\Communication\Models\CommunicationChannel;
use Modules\MarketingAutomation\Domain\Enums\MailProvider;

/**
 * Resolves the adapter and its credentials per tenant: the environment provides defaults, the `marketing_email`
 * channel of the Communication module overrides them for one tenant.
 */
final readonly class MailProviderRegistry
{
    public function __construct(
        private SmtpMailAdapter $smtp,
        private InfomaniakMailAdapter $infomaniak,
        private SendGridMailAdapter $sendGrid,
        private MailgunMailAdapter $mailgun,
        private AmazonSesMailAdapter $ses,
    ) {}

    public function adapter(MailProvider|string $provider): MailProviderAdapter
    {
        $provider = $provider instanceof MailProvider ? $provider : (MailProvider::tryFrom($provider) ?? MailProvider::Smtp);

        return match ($provider) {
            MailProvider::Smtp => $this->smtp,
            MailProvider::Infomaniak => $this->infomaniak,
            MailProvider::SendGrid => $this->sendGrid,
            MailProvider::Mailgun => $this->mailgun,
            MailProvider::AmazonSes => $this->ses,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function config(string $tenantId, MailProvider|string $provider): array
    {
        $name = $provider instanceof MailProvider ? $provider->value : $provider;
        $config = (array) config('marketing_automation.providers.'.$name, []);

        $channel = CommunicationChannel::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('channel', 'marketing_email')->where('driver', $name)->where('is_enabled', true)->first();
        if ($channel !== null) {
            $config = array_replace($config, (array) $channel->credentials);
        }

        return $config;
    }
}
