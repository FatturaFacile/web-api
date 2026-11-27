<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Services;

use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\SwissEbill\Application\Dto\GatewayNotification;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Enums\EbillNotificationType;
use Modules\SwissEbill\Domain\Enums\EbillRejectionCode;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Domain\Events\EbillApproved;
use Modules\SwissEbill\Domain\Events\EbillCancelled;
use Modules\SwissEbill\Domain\Events\EbillDelivered;
use Modules\SwissEbill\Domain\Events\EbillFailed;
use Modules\SwissEbill\Domain\Events\EbillPaid;
use Modules\SwissEbill\Domain\Events\EbillRejected;
use Modules\SwissEbill\Domain\Events\EbillTransmitted;
use Modules\SwissEbill\Domain\Exceptions\InvalidTransitionException;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Modules\SwissEbill\Models\EbillTransmission;

/**
 * Orchestrates a status change: the transition (guarded by the aggregate), the summary on the invoice and the domain event,
 * all in one transaction, with the event dispatched only once the change is committed.
 */
final class EbillTransmissionLifecycle
{
    public const string OUTCOME_APPLIED = 'applied';

    public const string OUTCOME_DUPLICATE_STATE = 'duplicate_state';

    public const string OUTCOME_OUT_OF_ORDER = 'ignored_out_of_order';

    public function __construct(private readonly EbillTransmissionRepositoryInterface $transmissions) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $details
     *
     * @throws InvalidTransitionException
     */
    public function transition(
        EbillTransmission $transmission,
        EbillTransmissionStatus $to,
        EbillEventSource $source,
        array $attributes = [],
        array $details = [],
        ?string $gatewayEventId = null,
        ?CarbonInterface $at = null,
        ?string $userId = null,
        ?string $eventType = null,
    ): EbillTransmission {
        return DB::transaction(function () use ($transmission, $to, $source, $attributes, $details, $gatewayEventId, $at, $userId, $eventType): EbillTransmission {
            $locked = EbillTransmission::query()->lockForUpdate()->findOrFail($transmission->id);
            $locked->applyTransition($to, $source, $attributes, $details, $gatewayEventId, $at, $userId, $eventType);
            $this->syncInvoiceSummary($locked, $at ?? now());

            $event = $this->domainEvent($locked, ($at ?? now())->toIso8601String());

            if ($event !== null) {
                DB::afterCommit(static fn () => Event::dispatch($event));
            }

            return $locked;
        });
    }

    /**
     * Applies a gateway notification (webhook or poll) tolerating the disorder of an asynchronous network: a repeated state or a
     * notification older than the current state is acknowledged and ignored, an impossible move raises InvalidTransitionException.
     *
     * @return array{outcome: string, transmission: EbillTransmission}
     *
     * @throws InvalidTransitionException
     */
    public function applyNotification(EbillTransmission $transmission, GatewayNotification $notification, EbillEventSource $source): array
    {
        $target = $notification->type->targetStatus();
        $current = $transmission->status;

        if ($current === $target) {
            return ['outcome' => self::OUTCOME_DUPLICATE_STATE, 'transmission' => $transmission];
        }

        if (! $current->canTransitionTo($target) && $current->rank() >= $target->rank()) {
            return ['outcome' => self::OUTCOME_OUT_OF_ORDER, 'transmission' => $transmission];
        }

        $updated = $this->transition(
            $transmission,
            $target,
            $source,
            $this->attributesFor($notification),
            $notification->toArray(),
            $notification->eventId,
            $notification->occurredAt,
        );

        return ['outcome' => self::OUTCOME_APPLIED, 'transmission' => $updated];
    }

    /**
     * Sets the eBill summary of the invoice from its latest transmission (the invoice is issued, only this delivery state moves).
     */
    public function syncInvoiceSummary(EbillTransmission $changed, CarbonInterface $at): void
    {
        $latest = $this->transmissions->latestForInvoice($changed->invoice_id) ?? $changed;
        $invoice = Invoice::query()->find($changed->invoice_id);

        if ($invoice === null) {
            return;
        }

        $invoice->forceFill([
            'ebill_status' => $latest->status->value,
            'ebill_transmission_id' => $latest->id,
            'ebill_last_event_at' => $at,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(GatewayNotification $notification): array
    {
        return match ($notification->type) {
            EbillNotificationType::Approved => ['scheduled_payment_date' => $notification->scheduledPaymentDate?->toDateString()],
            EbillNotificationType::Rejected => [
                'rejection_code' => EbillRejectionCode::fromGateway($notification->rejectionCode)->value,
                'rejection_reason' => $notification->rejectionReason,
            ],
            EbillNotificationType::Failed => ['error_code' => $notification->errorCode ?? 'gateway_failure', 'error_details' => $notification->errorDetails],
            EbillNotificationType::Settled => ['camt054_entry_id' => $notification->settlementReference, 'settlement_value_date' => $notification->occurredAt->toDateString()],
            default => [],
        };
    }

    private function domainEvent(EbillTransmission $t, string $at): ?object
    {
        $args = [$t->id, $t->invoice_id, (string) $t->tenant_id, $at];

        return match ($t->status) {
            EbillTransmissionStatus::Transmitted => new EbillTransmitted(...$args),
            EbillTransmissionStatus::DeliveredToEbanking => new EbillDelivered(...$args),
            EbillTransmissionStatus::ApprovedByDebtor => new EbillApproved(...$args),
            EbillTransmissionStatus::RejectedByDebtor => new EbillRejected(...$args),
            EbillTransmissionStatus::PaidReconciled => new EbillPaid(...$args),
            EbillTransmissionStatus::FailedTechnical => new EbillFailed(...$args),
            EbillTransmissionStatus::Cancelled => new EbillCancelled(...$args),
            default => null,
        };
    }
}
