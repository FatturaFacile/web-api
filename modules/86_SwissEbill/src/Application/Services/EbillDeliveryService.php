<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Services;

use Modules\Observability\Metrics\DomainMetrics;
use Modules\SwissEbill\Application\Dto\GatewaySubmission;
use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Modules\SwissEbill\Infrastructure\Gateways\EbillGatewayFactory;
use Modules\SwissEbill\Models\EbillTransmission;

/**
 * Hands a queued transmission to the gateway. Safe to run twice: only a queued transmission is sent, and the stable
 * idempotency key makes the gateway return the same receipt for a delivery whose answer was lost.
 */
final class EbillDeliveryService
{
    public function __construct(
        private readonly EbillSettingRepositoryInterface $settings,
        private readonly EbillTransmissionRepositoryInterface $transmissions,
        private readonly EbillGatewayFactory $gateways,
        private readonly EbillTransmissionLifecycle $lifecycle,
    ) {}

    /**
     * @throws EbillGatewayException a transient failure: the caller retries with backoff
     */
    public function deliver(string $transmissionId): void
    {
        $transmission = $this->transmissions->find($transmissionId);

        if ($transmission === null || $transmission->status !== EbillTransmissionStatus::Queued) {
            return;
        }

        $setting = $this->settings->current();

        if ($setting === null) {
            $this->fail($transmission, 'not_configured', 'The eBill settings of the company are gone.');

            return;
        }

        $transmission->increment('attempt_count');
        $transmission->refresh();

        try {
            $receipt = $this->gateways->for($setting)->submit($setting, new GatewaySubmission(
                $transmission->idempotency_key,
                $transmission->biller_id,
                $transmission->recipient_id,
                $transmission->channel_type ?? EbillChannelType::B2cPrivate,
                (string) $transmission->invoice?->invoice_number,
                (string) $transmission->xml_payload,
                (string) $transmission->xml_sha256,
            ));
        } catch (EbillGatewayException $e) {
            DomainMetrics::fiscalTransmission('EBILL', 'failure');

            if ($e->isTransient()) {
                $this->scheduleRetry($transmission, $e);

                throw $e;
            }

            $this->fail($transmission, $e->errorCode(), $e->getMessage(), $e->gatewayStatus);

            return;
        } catch (EbillException $e) {
            DomainMetrics::fiscalTransmission('EBILL', 'failure');
            $this->fail($transmission, $e->errorCode(), $e->getMessage());

            return;
        }

        DomainMetrics::fiscalTransmission('EBILL', 'success');
        $this->lifecycle->transition(
            $transmission,
            EbillTransmissionStatus::Transmitted,
            EbillEventSource::System,
            ['six_transaction_id' => $receipt->sixTransactionId, 'submission_timestamp' => $receipt->submittedAt, 'next_retry_at' => null, 'error_code' => null, 'error_details' => null],
            ['six_transaction_id' => $receipt->sixTransactionId, 'attempt' => $transmission->attempt_count],
            null,
            $receipt->submittedAt,
        );
    }

    /**
     * Gives a queued transmission up (retries exhausted, or a permanent gateway refusal).
     */
    public function fail(EbillTransmission $transmission, string $code, string $message, ?int $httpStatus = null): void
    {
        $fresh = $this->transmissions->find($transmission->id);

        if ($fresh === null || ! $fresh->status->canTransitionTo(EbillTransmissionStatus::FailedTechnical)) {
            return;
        }

        $this->lifecycle->transition(
            $fresh,
            EbillTransmissionStatus::FailedTechnical,
            EbillEventSource::System,
            ['error_code' => mb_substr($code, 0, 80), 'error_details' => $message, 'next_retry_at' => null],
            ['error_code' => $code, 'http_status' => $httpStatus, 'attempt' => $fresh->attempt_count],
        );
    }

    private function scheduleRetry(EbillTransmission $transmission, EbillGatewayException $e): void
    {
        $backoff = array_values((array) config('swiss_ebill.retry_backoff_seconds', [30]));
        $delay = (int) ($backoff[min($transmission->attempt_count - 1, count($backoff) - 1)] ?? 60);
        $transmission->forceFill(['next_retry_at' => now()->addSeconds($delay), 'error_code' => mb_substr($e->errorCode(), 0, 80), 'error_details' => $e->getMessage()])->save();
        $transmission->appendEvent('retry_scheduled', EbillEventSource::System, EbillTransmissionStatus::Queued, EbillTransmissionStatus::Queued, [
            'attempt' => $transmission->attempt_count, 'delay_seconds' => $delay, 'http_status' => $e->gatewayStatus, 'error' => $e->getMessage(),
        ]);
    }
}
