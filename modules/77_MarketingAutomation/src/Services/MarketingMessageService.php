<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Services\MessageDispatcher;
use Modules\MarketingAutomation\Domain\Enums\MessageStatus;
use Modules\MarketingAutomation\Domain\ValueObjects\RecipientContext;
use Modules\MarketingAutomation\Exceptions\MailDeliveryException;
use Modules\MarketingAutomation\Jobs\SendMarketingMessageJob;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Models\MarketingMessage;
use Modules\MarketingAutomation\Services\Delivery\MailProviderRegistry;
use Modules\MarketingAutomation\Services\Delivery\OutboundEmail;
use Modules\MarketingAutomation\Services\Mjml\CampaignTemplateService;
use Modules\MarketingAutomation\Services\Privacy\PrivacyStrategyResolver;

/**
 * Gatekeeper and pipeline of every promotional message: consent and suppression and credit semaphore checks, queued
 * delivery through the provider adapters (e-mail) or the Communication module (WhatsApp, SMS).
 */
final readonly class MarketingMessageService
{
    public function __construct(
        private PrivacyStrategyResolver $strategies,
        private SuppressionService $suppressions,
        private ConsentLedgerService $consents,
        private InsolvencyGuard $insolvency,
        private TrackingService $tracking,
        private CampaignTemplateService $templates,
        private MailProviderRegistry $providers,
        private MarketingCopy $copy,
        private MetricsService $metrics,
        private MessageDispatcher $dispatcher,
    ) {}

    /**
     * Checks the rules and creates the queued message; the reason tells why a recipient was refused.
     *
     * @return array{message: MarketingMessage|null, reason: string|null}
     */
    public function prepare(Campaign $campaign, string $contactId, ?string $variant = null, string $channel = 'email'): array
    {
        $tenantId = (string) $campaign->tenant_id;
        $contact = DB::table('contacts')->where('tenant_id', $tenantId)->where('id', $contactId)->whereNull('deleted_at')->first();
        if ($contact === null || $contact->anonymized_at !== null || $contact->processing_restricted_at !== null) {
            return ['message' => null, 'reason' => 'contact_unavailable'];
        }

        $address = $channel === 'email' ? mb_strtolower(trim((string) $contact->email)) : trim((string) $contact->phone);
        if ($address === '') {
            return ['message' => null, 'reason' => 'no_address'];
        }

        if ($this->insolvency->isBlocked($tenantId, $contactId)) {
            return ['message' => null, 'reason' => 'credit_blocked'];
        }

        $tenantCountry = (string) (Tenant::query()->whereKey($tenantId)->value('country') ?? 'CH');
        $consent = $this->consents->current($tenantId, $contactId);
        $strategy = $this->strategies->forJurisdiction($contact->country, $tenantCountry);
        $locale = $this->locale((string) ($contact->document_language ?? ''), $consent?->locale);
        $existingCustomer = DB::table('invoices')->where('tenant_id', $tenantId)->where('contact_id', $contactId)->whereNotNull('posted_at')->exists();

        $decision = $strategy->evaluate($consent, new RecipientContext(
            jurisdiction: strtoupper((string) ($contact->country ?: $tenantCountry)),
            locale: $locale,
            isExistingCustomer: $existingCustomer,
            boughtSimilarProducts: $existingCustomer,
            senderIdentified: trim((string) $campaign->sender_name) !== '' && (trim((string) $campaign->sender_postal_address) !== '' || $channel !== 'email'),
            addressSuppressed: $this->suppressions->isSuppressed($tenantId, $address, $channel),
        ), $channel);

        if (! $decision->allowed) {
            return ['message' => null, 'reason' => $decision->reasonCode];
        }

        $message = MarketingMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'campaign_id' => $campaign->id,
            'contact_id' => $contactId,
            'token' => $this->tracking->newToken(),
            'channel' => $channel,
            'recipient' => $address,
            'variant' => $variant,
            'locale' => $locale,
            'status' => MessageStatus::Queued->value,
        ]);

        return ['message' => $message, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>|null  $stepConfig
     */
    public function queue(MarketingMessage $message, ?array $stepConfig = null): void
    {
        SendMarketingMessageJob::dispatch((string) $message->id, $stepConfig);
    }

    /**
     * @param  array<string, mixed>|null  $stepConfig
     */
    public function deliver(MarketingMessage $message, ?array $stepConfig = null): MarketingMessage
    {
        if ($message->status !== MessageStatus::Queued->value) {
            return $message;
        }

        $campaign = Campaign::withoutGlobalScopes()->findOrFail($message->campaign_id);

        // The address may have been suppressed between queueing and sending.
        if ($this->suppressions->isSuppressed((string) $message->tenant_id, (string) $message->recipient, (string) $message->channel)) {
            $message->forceFill(['status' => MessageStatus::Suppressed->value])->save();

            return $message;
        }

        try {
            if ($message->channel === 'email') {
                $this->deliverEmail($message, $campaign);
            } else {
                $this->deliverChat($message, $campaign, $stepConfig);
            }
        } catch (MailDeliveryException $e) {
            if ($e->permanent) {
                $this->tracking->registerProviderEvent(['type' => 'hard_bounce', 'address' => $message->recipient, 'reference' => null], (string) $message->tenant_id);
            }
            $message->refresh()->forceFill(['status' => $e->permanent ? MessageStatus::HardBounced->value : MessageStatus::Failed->value, 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
        }

        $this->metrics->recompute($campaign);

        return $message->refresh();
    }

    private function deliverEmail(MarketingMessage $message, Campaign $campaign): void
    {
        $contact = DB::table('contacts')->where('id', $message->contact_id)->first();
        $rendered = $this->templates->render((array) $campaign->template_mjml_json, (string) $message->locale, $message->variant);

        $unsubscribe = $this->tracking->unsubscribeUrl((string) $message->token);
        $html = $this->personalise($rendered['html'], $contact, $unsubscribe);
        $html = $this->rewriteLinks($html, $campaign, $message, $unsubscribe);
        $html = $this->injectFooterAndPixel($html, $campaign, $message, $unsubscribe);

        $tenantCountry = (string) (Tenant::query()->whereKey($message->tenant_id)->value('country') ?? 'CH');
        $strategy = $this->strategies->forJurisdiction($contact->country ?? null, $tenantCountry);
        $mailbox = (string) config('marketing_automation.unsubscribe_mailbox', '');

        $email = new OutboundEmail(
            to: (string) $message->recipient,
            fromEmail: (string) $campaign->sender_email,
            fromName: (string) $campaign->sender_name,
            subject: $this->personalise($rendered['subject'], $contact, $unsubscribe),
            html: $html,
            text: trim(html_entity_decode(strip_tags(preg_replace('#<(style|head)[^>]*>.*?</\1>#is', '', $html) ?? $html))),
            headers: $strategy->unsubscribeHeaders($unsubscribe, $mailbox !== '' ? $mailbox : null) + ['X-FF-Campaign' => (string) $campaign->id],
        );

        $result = $this->providers->adapter((string) $campaign->provider)->send($email, $this->providers->config((string) $message->tenant_id, (string) $campaign->provider));

        $message->forceFill(['status' => MessageStatus::Sent->value, 'sent_at' => Carbon::now(), 'provider_reference' => $result['reference']])->save();
    }

    /**
     * @param  array<string, mixed>|null  $stepConfig
     */
    private function deliverChat(MarketingMessage $message, Campaign $campaign, ?array $stepConfig): void
    {
        $locale = (string) $message->locale;
        $texts = (array) ($stepConfig['message'] ?? $campaign->template_mjml_json['sms'] ?? []);
        $body = (string) ($texts[$locale] ?? $texts['it'] ?? $texts['en'] ?? '');
        $body = trim($body."\n".$this->copy->get('footer.chat_optout', $locale));
        $contact = DB::table('contacts')->where('id', $message->contact_id)->first();
        $body = $this->personalise($body, $contact, $this->tracking->unsubscribeUrl((string) $message->token));

        try {
            $settings = $this->dispatcher->settings((string) $message->channel);
        } catch (ValidationException $e) {
            throw new MailDeliveryException('Channel disabled: '.implode(' ', $e->errors()['channel'] ?? []));
        }

        $log = $this->dispatcher->dispatch((string) $message->channel, new DeliveryMessage((string) $message->channel, (string) $message->recipient, (string) $campaign->name, $body), [
            'tenant_id' => $message->tenant_id, 'message_type' => 'marketing', 'template_code' => 'mod155', 'locale' => $locale,
        ], $settings);

        if ($log->status === 'failed') {
            throw new MailDeliveryException((string) $log->error);
        }

        $message->forceFill(['status' => MessageStatus::Sent->value, 'sent_at' => Carbon::now(), 'provider_reference' => $log->provider_reference])->save();
    }

    private function personalise(string $text, ?object $contact, string $unsubscribeUrl): string
    {
        $company = (string) ($contact->company_name ?? '');
        $first = (string) ($contact->first_name ?? '');

        return strtr($text, [
            '{{first_name}}' => $first !== '' ? $first : $company,
            '{{last_name}}' => (string) ($contact->last_name ?? ''),
            '{{company_name}}' => $company,
            '{{unsubscribe_url}}' => $unsubscribeUrl,
        ]);
    }

    private function rewriteLinks(string $html, Campaign $campaign, MarketingMessage $message, string $unsubscribe): string
    {
        return (string) preg_replace_callback('/href="(https?:\/\/[^"]+)"/i', function (array $m) use ($campaign, $message, $unsubscribe): string {
            $url = html_entity_decode($m[1]);
            if ($url === $unsubscribe) {
                return $m[0];
            }

            return 'href="'.e($this->tracking->clickUrl((string) $message->token, $this->tracking->withUtm($url, $campaign, $message))).'"';
        }, $html);
    }

    private function injectFooterAndPixel(string $html, Campaign $campaign, MarketingMessage $message, string $unsubscribe): string
    {
        $locale = (string) $message->locale;
        $country = (string) (DB::table('contacts')->where('id', $message->contact_id)->value('country') ?? '');
        $tenantCountry = (string) (Tenant::query()->whereKey($message->tenant_id)->value('country') ?? 'CH');
        $strategy = $this->strategies->forJurisdiction($country, $tenantCountry);

        $lines = [];
        foreach ($strategy->requiredFooterKeys() as $key) {
            $lines[] = $this->copy->get($key, $locale, [
                'sender_name' => (string) $campaign->sender_name,
                'sender_address' => (string) $campaign->sender_postal_address,
                'unsubscribe_url' => $unsubscribe,
            ]);
        }

        $footer = '<div style="font-family:Arial,sans-serif;font-size:12px;color:#666666;text-align:center;padding:16px;">'.implode('<br>', array_filter($lines)).'</div>'
            .'<img src="'.e($this->tracking->openUrl((string) $message->token)).'" width="1" height="1" alt="" style="display:block;border:0;">';

        return str_contains($html, '</body>') ? str_replace('</body>', $footer.'</body>', $html) : $html.$footer;
    }

    private function locale(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $code = strtolower(substr($candidate, 0, 2));
            if (in_array($code, SegmentEvaluationService::LOCALES, true)) {
                return $code;
            }
        }

        return 'it';
    }
}
