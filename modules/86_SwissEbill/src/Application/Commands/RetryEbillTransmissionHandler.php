<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

use Modules\SwissEbill\Application\Services\EbillTransmissionLifecycle;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Modules\SwissEbill\Infrastructure\Jobs\TransmitEbillJob;
use Modules\SwissEbill\Models\EbillTransmission;

/**
 * Sends a failed_technical transmission again (same payload, same idempotency key).
 */
final class RetryEbillTransmissionHandler
{
    public function __construct(
        private readonly EbillTransmissionRepositoryInterface $transmissions,
        private readonly EbillTransmissionLifecycle $lifecycle,
    ) {}

    public function handle(string $transmissionId, ?string $userId = null): EbillTransmission
    {
        $transmission = $this->transmissions->findOrFail($transmissionId);

        if ($transmission->status !== EbillTransmissionStatus::FailedTechnical) {
            throw new EbillException('cannot_retry', 'Only a transmission that failed technically can be retried.', 409);
        }

        // A retry of a failed transmission is refused when another one of the invoice is alive in the meantime.
        $active = $this->transmissions->activeForInvoice($transmission->invoice_id);

        if ($active !== null && $active->id !== $transmission->id) {
            throw new EbillException('already_transmitted', 'This invoice already has an active eBill transmission.', 409);
        }

        $queued = $this->lifecycle->transition(
            $transmission,
            EbillTransmissionStatus::Queued,
            EbillEventSource::User,
            ['attempt_count' => 0, 'next_retry_at' => null, 'error_code' => null, 'error_details' => null],
            ['previous_error' => $transmission->error_code],
            null,
            null,
            $userId,
            'retry_requested',
        );

        TransmitEbillJob::dispatch($queued->id, (string) $queued->tenant_id)->afterCommit();

        return $queued;
    }
}
