<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use Modules\Communication\Contracts\ConsentLookup;
use Modules\CRM\Services\ConsentService;

/**
 * Consent lookup backed by the consent records of the CRM. The CRM records consent per email, sms, phone and post: the
 * instant messengers (WhatsApp, Telegram) are electronic messages to a phone or account and follow the sms consent.
 */
final readonly class CrmConsentLookup implements ConsentLookup
{
    public function __construct(private ConsentService $consents) {}

    public function status(?string $contactId, ?string $leadId, string $channel, string $purpose): ?string
    {
        $crmChannel = $channel === 'email' ? 'email' : 'sms';

        return $this->consents->effectiveStatus($contactId, $leadId, $crmChannel, $purpose);
    }
}
