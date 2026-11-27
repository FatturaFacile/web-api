<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

use Modules\SwissEbill\Application\Services\EbillTransmissionLifecycle;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Modules\SwissEbill\Infrastructure\Gateways\EbillGatewayFactory;
use Modules\SwissEbill\Models\EbillTransmission;

/**
 * Recalls an invoice before the debtor approved it. A transmission the gateway already holds is recalled there first; one
 * that never left (draft, queued) is simply cancelled.
 */
final class CancelEbillTransmissionHandler
{
    public function __construct(
        private readonly EbillTransmissionRepositoryInterface $transmissions,
        private readonly EbillSettingRepositoryInterface $settings,
        private readonly EbillGatewayFactory $gateways,
        private readonly EbillTransmissionLifecycle $lifecycle,
    ) {}

    /**
     * @throws EbillException cannot_cancel (409) when the state does not allow it, gateway_error when the recall is refused
     */
    public function handle(string $transmissionId, ?string $userId = null): EbillTransmission
    {
        $transmission = $this->transmissions->findOrFail($transmissionId);

        if (! $transmission->status->canTransitionTo(EbillTransmissionStatus::Cancelled)) {
            throw new EbillException('cannot_cancel', 'This transmission can no longer be recalled.', 409);
        }

        if ($transmission->six_transaction_id !== null) {
            $setting = $this->settings->current();

            if ($setting === null) {
                throw new EbillException('not_configured', 'Swiss eBill is not configured for this company.', 422);
            }

            try {
                $this->gateways->for($setting)->recall($setting, $transmission->six_transaction_id);
            } catch (EbillGatewayException $e) {
                throw new EbillException($e->gatewayStatus === 409 ? 'cannot_cancel' : 'gateway_error', $e->getMessage(), $e->gatewayStatus === 409 ? 409 : 502);
            }
        }

        return $this->lifecycle->transition($transmission, EbillTransmissionStatus::Cancelled, EbillEventSource::User, [], ['recalled' => $transmission->six_transaction_id !== null], null, null, $userId);
    }
}
