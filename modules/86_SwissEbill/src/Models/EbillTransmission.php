<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Models;

use App\Models\Contact;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Enums\EbillTransmissionStatus;
use Modules\SwissEbill\Domain\Exceptions\InvalidTransitionException;

/**
 * One attempt to deliver an invoice through the Swiss eBill network. The aggregate owns the state machine: the status only
 * changes through applyTransition(), which refuses every move the lifecycle does not allow and appends the immutable event.
 *
 * @property string $invoice_id
 * @property string|null $contact_id
 * @property string|null $supersedes_id
 * @property EbillTransmissionStatus $status
 * @property EbillChannelType|null $channel_type
 * @property string $idempotency_key
 * @property int $attempt_count
 * @property string $biller_id
 * @property string $recipient_id
 * @property string|null $six_transaction_id
 * @property Carbon|null $submission_timestamp
 * @property Carbon|null $delivered_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $scheduled_payment_date
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_code
 * @property string|null $rejection_reason
 * @property string|null $error_code
 * @property string|null $error_details
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $paid_at
 * @property string|null $camt054_entry_id
 * @property Carbon|null $settlement_value_date
 * @property string $settled_amount
 * @property string|null $amount_discrepancy
 * @property Carbon|null $cancelled_at
 * @property string $invoice_amount
 * @property string $currency
 * @property string|null $ebinterface_version
 * @property string|null $xml_payload
 * @property string|null $xml_sha256
 * @property string|null $pdf_sha256
 * @property int|null $pdf_size_bytes
 */
final class EbillTransmission extends BaseEnterpriseModel
{
    protected $table = 'ebill_transmissions';

    /**
     * The payload is large and only read on demand (audit download).
     *
     * @var list<string>
     */
    protected $hidden = ['xml_payload'];

    protected function casts(): array
    {
        return [
            'status' => EbillTransmissionStatus::class,
            'channel_type' => EbillChannelType::class,
            'attempt_count' => 'integer',
            'submission_timestamp' => 'datetime',
            'delivered_at' => 'datetime',
            'approved_at' => 'datetime',
            'scheduled_payment_date' => 'date',
            'rejected_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'paid_at' => 'datetime',
            'settlement_value_date' => 'date',
            'settled_amount' => 'decimal:2',
            'amount_discrepancy' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'invoice_amount' => 'decimal:2',
            'pdf_size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<EbillTransmissionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EbillTransmissionEvent::class, 'transmission_id')->orderBy('occurred_at')->orderBy('created_at')->orderBy('id');
    }

    /**
     * Moves the transmission to a new status, stamps the columns of that status and appends the event of the trail. The caller
     * saves inside its own transaction, so the event and the status change together.
     *
     * @param  array<string, mixed>  $attributes  columns that belong to the new status (six_transaction_id, rejection_code, ...)
     * @param  array<string, mixed>  $details  free data of the event (payload excerpts, error text)
     *
     * @throws InvalidTransitionException
     */
    public function applyTransition(
        EbillTransmissionStatus $to,
        EbillEventSource $source,
        array $attributes = [],
        array $details = [],
        ?string $gatewayEventId = null,
        ?CarbonInterface $at = null,
        ?string $userId = null,
        ?string $eventType = null,
    ): EbillTransmissionEvent {
        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidTransitionException($from, $to);
        }

        $at ??= now();
        $this->forceFill($attributes);
        $this->status = $to;
        $this->stampStatusColumn($to, $at);
        $this->save();

        return $this->appendEvent($eventType ?? $to->value, $source, $from, $to, $details, $gatewayEventId, $at, $userId);
    }

    /**
     * Appends an event that does not change the status (a retry scheduled, a partial settlement, a payload downloaded).
     *
     * @param  array<string, mixed>  $details
     */
    public function appendEvent(
        string $eventType,
        EbillEventSource $source,
        ?EbillTransmissionStatus $from = null,
        ?EbillTransmissionStatus $to = null,
        array $details = [],
        ?string $gatewayEventId = null,
        ?CarbonInterface $at = null,
        ?string $userId = null,
    ): EbillTransmissionEvent {
        return EbillTransmissionEvent::query()->create([
            'tenant_id' => $this->tenant_id,
            'transmission_id' => $this->id,
            'event_type' => $eventType,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'source' => $source->value,
            'gateway_event_id' => $gatewayEventId,
            'occurred_at' => $at ?? now(),
            'details' => $details === [] ? null : $details,
            'created_by' => $userId,
        ]);
    }

    public function isOverdueForRetry(): bool
    {
        return $this->status === EbillTransmissionStatus::FailedTechnical && $this->next_retry_at !== null && $this->next_retry_at->isPast();
    }

    public function isFullySettled(): bool
    {
        return (float) $this->settled_amount + 0.005 >= (float) $this->invoice_amount;
    }

    private function stampStatusColumn(EbillTransmissionStatus $to, CarbonInterface $moment): void
    {
        $at = Carbon::instance($moment);

        match ($to) {
            EbillTransmissionStatus::Transmitted => $this->submission_timestamp ??= $at,
            EbillTransmissionStatus::DeliveredToEbanking => $this->delivered_at = $at,
            EbillTransmissionStatus::ApprovedByDebtor => $this->approved_at = $at,
            EbillTransmissionStatus::RejectedByDebtor => $this->rejected_at = $at,
            EbillTransmissionStatus::PaidReconciled => $this->paid_at = $at,
            EbillTransmissionStatus::Cancelled => $this->cancelled_at = $at,
            default => null,
        };
    }
}
