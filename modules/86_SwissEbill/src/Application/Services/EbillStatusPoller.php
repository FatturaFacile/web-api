<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Services;

use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Domain\Exceptions\InvalidTransitionException;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Modules\SwissEbill\Infrastructure\Gateways\EbillGatewayFactory;
use Modules\SwissEbill\Infrastructure\Jobs\TransmitEbillJob;

/**
 * Polling fallback of the webhooks for companies behind a restrictive firewall: asks the gateway for the notifications of every
 * transmission still in flight and feeds them through the same deduplicating processor as the webhook, and sends again the queued
 * transmissions whose job got lost. Runs inside the tenant context of the company.
 */
final class EbillStatusPoller
{
    public function __construct(
        private readonly EbillSettingRepositoryInterface $settings,
        private readonly EbillTransmissionRepositoryInterface $transmissions,
        private readonly EbillGatewayFactory $gateways,
        private readonly EbillNotificationProcessor $processor,
    ) {}

    /**
     * @return array{polled: int, applied: int, failed: int, redispatched: int}
     */
    public function pollCurrentTenant(): array
    {
        $result = ['polled' => 0, 'applied' => 0, 'failed' => 0, 'redispatched' => 0];
        $setting = $this->settings->current();

        if ($setting === null || ! $setting->isReadyToTransmit()) {
            return $result;
        }

        foreach ($this->transmissions->staleQueued((int) config('swiss_ebill.poll_stale_after_minutes', 15)) as $queued) {
            TransmitEbillJob::dispatch($queued->id, (string) $queued->tenant_id);
            $result['redispatched']++;
        }

        $gateway = $this->gateways->for($setting);

        foreach ($this->transmissions->inFlight() as $transmission) {
            $result['polled']++;

            try {
                $notifications = $gateway->fetchStatus($setting, (string) $transmission->six_transaction_id);
            } catch (EbillGatewayException) {
                $result['failed']++;

                continue;
            }

            foreach ($notifications as $notification) {
                try {
                    $outcome = $this->processor->process($setting, $notification, EbillEventSource::GatewayPoll);
                } catch (InvalidTransitionException|EbillException) {
                    // An impossible or unknown notification is left for the next run (and for the user to see on the timeline).
                    continue;
                }

                if ($outcome['outcome'] === EbillTransmissionLifecycle::OUTCOME_APPLIED) {
                    $result['applied']++;
                }
            }
        }

        return $result;
    }
}
