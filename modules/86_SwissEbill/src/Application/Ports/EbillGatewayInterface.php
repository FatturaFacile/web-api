<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Ports;

use Modules\SwissEbill\Application\Dto\GatewayConnectionResult;
use Modules\SwissEbill\Application\Dto\GatewayNotification;
use Modules\SwissEbill\Application\Dto\GatewayReceipt;
use Modules\SwissEbill\Application\Dto\GatewaySubmission;
use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Application\Dto\ParticipantRecord;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Models\EbillSetting;

/**
 * The port to a Swiss eBill network access point (SIX Paynet direct, PostFinance eBill) or to the sandbox simulator.
 */
interface EbillGatewayInterface
{
    /**
     * Verifies the credentials and the reachability of the gateway. Never throws: a failure is a result.
     */
    public function testConnection(EbillSetting $setting): GatewayConnectionResult;

    /**
     * @throws EbillGatewayException
     */
    public function lookupParticipant(EbillSetting $setting, ParticipantLookupCriteria $criteria): ?ParticipantRecord;

    /**
     * Hands an invoice to the network. The idempotency key makes a repeated call return the same receipt.
     *
     * @throws EbillGatewayException
     */
    public function submit(EbillSetting $setting, GatewaySubmission $submission): GatewayReceipt;

    /**
     * Recalls an invoice the debtor has not approved yet.
     *
     * @throws EbillGatewayException
     */
    public function recall(EbillSetting $setting, string $sixTransactionId): void;

    /**
     * Status notifications of a transaction in chronological order (the polling fallback of the webhooks).
     *
     * @return list<GatewayNotification>
     *
     * @throws EbillGatewayException
     */
    public function fetchStatus(EbillSetting $setting, string $sixTransactionId): array;
}
