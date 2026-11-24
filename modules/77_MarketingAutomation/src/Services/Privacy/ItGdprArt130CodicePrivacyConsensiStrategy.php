<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Privacy;

use Modules\MarketingAutomation\Domain\Contracts\IMarketingPrivacyComplianceStrategy;
use Modules\MarketingAutomation\Domain\ValueObjects\PrivacyDecision;
use Modules\MarketingAutomation\Domain\ValueObjects\RecipientContext;
use Modules\MarketingAutomation\Models\MarketingConsent;

/**
 * Italy / EU: Art. 130 D.Lgs. 196/2003 and GDPR. Direct marketing and RFM profiling are separate consents; the soft
 * spam of Art. 130 comma 4 covers similar products sold to an existing customer who can object at any time.
 */
final class ItGdprArt130CodicePrivacyConsensiStrategy implements IMarketingPrivacyComplianceStrategy
{
    public const string CODE = 'it_gdpr_art130';

    public function code(): string
    {
        return self::CODE;
    }

    public function supports(string $jurisdiction): bool
    {
        return strtoupper($jurisdiction) !== 'CH';
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
        $prefs = $consent->channel_preferences ?? [];
        if (array_key_exists($channel, $prefs) && $prefs[$channel] === false) {
            return PrivacyDecision::deny('channel_opt_out');
        }
        if ($consent?->marketing_opt_in === true) {
            return PrivacyDecision::allow('consent_art130_c1');
        }
        // Soft spam is an e-mail exception only; messengers and SMS always need the consent.
        if ($channel === 'email' && $context->isExistingCustomer && $context->boughtSimilarProducts && $consent?->soft_opt_in_eligible === true) {
            return PrivacyDecision::allow('soft_opt_in_art130_c4');
        }

        return PrivacyDecision::deny('no_consent');
    }

    public function allowsProfiling(?MarketingConsent $consent): bool
    {
        return $consent?->unsubscribed_at === null && $consent?->profiling_opt_in === true;
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
        return ['footer.sender_identity', 'footer.unsubscribe', 'footer.privacy_it'];
    }
}
