<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Privacy;

use Modules\MarketingAutomation\Domain\Contracts\IMarketingPrivacyComplianceStrategy;
use Modules\MarketingAutomation\Domain\ValueObjects\PrivacyDecision;
use Modules\MarketingAutomation\Domain\ValueObjects\RecipientContext;
use Modules\MarketingAutomation\Models\MarketingConsent;

/**
 * Switzerland: UWG/LCSl Art. 3 para. 1 lit. o (prior consent, or the soft opt-in for similar products of an existing
 * customer with a clear opt-out) and the nLPD (profiling is allowed with transparency, objection is honoured).
 */
final class ChLcslArt3OptInOutNlpdStrategy implements IMarketingPrivacyComplianceStrategy
{
    public const string CODE = 'ch_lcsl_art3_nlpd';

    public function code(): string
    {
        return self::CODE;
    }

    public function supports(string $jurisdiction): bool
    {
        return strtoupper($jurisdiction) === 'CH';
    }

    public function evaluate(?MarketingConsent $consent, RecipientContext $context, string $channel): PrivacyDecision
    {
        if ($context->addressSuppressed) {
            return PrivacyDecision::deny('suppressed');
        }
        if (! $context->senderIdentified) {
            return PrivacyDecision::deny('sender_not_identified');
        }
        if ($consent?->unsubscribed_at !== null) {
            return PrivacyDecision::deny('unsubscribed');
        }
        if ($consent !== null && $this->channelDisabled($consent, $channel)) {
            return PrivacyDecision::deny('channel_opt_out');
        }
        if ($consent?->marketing_opt_in === true) {
            return PrivacyDecision::allow('consent');
        }
        if ($context->isExistingCustomer && $context->boughtSimilarProducts && $consent?->soft_opt_in_eligible === true) {
            return PrivacyDecision::allow('soft_opt_in');
        }

        return PrivacyDecision::deny('no_consent');
    }

    public function allowsProfiling(?MarketingConsent $consent): bool
    {
        // nLPD: profiling needs no prior consent, only transparency and respect for the objection (unsubscribe).
        return $consent?->unsubscribed_at === null;
    }

    public function unsubscribeHeaders(string $httpsUrl, ?string $mailto): array
    {
        $targets = ['<'.$httpsUrl.'>'];
        if ($mailto !== null && $mailto !== '') {
            $targets[] = '<mailto:'.$mailto.'>';
        }

        return [
            'List-Unsubscribe' => implode(', ', $targets),
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    public function requiredFooterKeys(): array
    {
        return ['footer.sender_identity', 'footer.unsubscribe', 'footer.privacy_ch'];
    }

    private function channelDisabled(MarketingConsent $consent, string $channel): bool
    {
        $prefs = $consent->channel_preferences ?? [];

        return array_key_exists($channel, $prefs) && $prefs[$channel] === false;
    }
}
