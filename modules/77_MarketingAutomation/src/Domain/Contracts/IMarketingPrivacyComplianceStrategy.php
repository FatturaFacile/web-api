<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\Contracts;

use Modules\MarketingAutomation\Domain\ValueObjects\PrivacyDecision;
use Modules\MarketingAutomation\Domain\ValueObjects\RecipientContext;
use Modules\MarketingAutomation\Models\MarketingConsent;

/**
 * Jurisdiction specific rules for promotional outreach (CH LCSl/nLPD, IT GDPR + Art. 130 Codice Privacy).
 */
interface IMarketingPrivacyComplianceStrategy
{
    /**
     * Stable code stored on the consent ledger.
     */
    public function code(): string;

    public function supports(string $jurisdiction): bool;

    /**
     * May this recipient receive a promotional message over the channel?
     */
    public function evaluate(?MarketingConsent $consent, RecipientContext $context, string $channel): PrivacyDecision;

    /**
     * May the recipient be scored (RFM profiling) at all?
     */
    public function allowsProfiling(?MarketingConsent $consent): bool;

    /**
     * RFC 8058 headers for one-click unsubscribe.
     *
     * @return array<string, string>
     */
    public function unsubscribeHeaders(string $httpsUrl, ?string $mailto): array;

    /**
     * Text keys (seeded in ma_blueprints, kind text) the footer of every message has to carry.
     *
     * @return list<string>
     */
    public function requiredFooterKeys(): array;
}
