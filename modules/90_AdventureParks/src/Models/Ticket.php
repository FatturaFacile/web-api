<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\AdventureParks\Domain\Enums\TicketStatus;
use Modules\AdventureParks\Domain\ValueObjects\SlotWindow;
use Modules\AdventureParks\Exceptions\AdventureParksException;

/**
 * A booking of a timed slot: the harness sizes it holds in the stock, the visitors who sign the waiver, the PPE handed out and the
 * life cycle at the dressing desk. BOOKED -> WAIVER_SIGNED -> EQUIPPED -> ON_COURSE -> RETURNED, with NO_SHOW from BOOKED and
 * WAIVER_SIGNED. A ticket cannot be equipped before a signed waiver covers every participant.
 *
 * @property string|null $slot_template_id
 * @property string $booking_code
 * @property string $public_token
 * @property Carbon $slot_date
 * @property string $start_time
 * @property string $end_time
 * @property string $wristband_color
 * @property string $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property int $headcount
 * @property string $payment_status
 * @property string $status
 */
final class Ticket extends BaseEnterpriseModel
{
    /**
     * Allowed transitions of the life cycle.
     *
     * @var array<string, list<string>>
     */
    private const array TRANSITIONS = [
        'BOOKED' => ['WAIVER_SIGNED', 'NO_SHOW'],
        'WAIVER_SIGNED' => ['EQUIPPED', 'NO_SHOW'],
        'EQUIPPED' => ['ON_COURSE', 'RETURNED'],
        'ON_COURSE' => ['RETURNED'],
        'RETURNED' => [],
        'NO_SHOW' => [],
    ];

    protected $table = 'adv_tickets';

    protected function casts(): array
    {
        return [
            'slot_date' => 'date',
            'headcount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SlotTemplate, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(SlotTemplate::class, 'slot_template_id');
    }

    /**
     * @return HasMany<TicketLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TicketLine::class, 'ticket_id');
    }

    /**
     * @return HasMany<TicketParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(TicketParticipant::class, 'ticket_id');
    }

    /**
     * @return HasMany<TicketPpeAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TicketPpeAssignment::class, 'ticket_id');
    }

    /**
     * @return HasOne<SafetyWaiver, $this>
     */
    public function waiver(): HasOne
    {
        return $this->hasOne(SafetyWaiver::class, 'ticket_id');
    }

    public function statusEnum(): TicketStatus
    {
        return TicketStatus::from($this->status);
    }

    public function window(): SlotWindow
    {
        return SlotWindow::fromTimes($this->start_time, $this->end_time);
    }

    /**
     * Whether the ticket holds harnesses in the stock of its slot (a no-show or a returned ticket does not).
     */
    public function holdsStock(): bool
    {
        return ! in_array($this->statusEnum(), [TicketStatus::Returned, TicketStatus::NoShow], true);
    }

    public function canTransitionTo(TicketStatus $target): bool
    {
        return in_array($target->value, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(TicketStatus $target): void
    {
        if (! $this->canTransitionTo($target)) {
            throw AdventureParksException::refuse('invalid_transition', 'The ticket cannot go from '.$this->status.' to '.$target->value.'.', [
                'from' => $this->status,
                'to' => $target->value,
            ]);
        }

        $this->status = $target->value;
    }

    /**
     * Records the signed waiver. Every participant (the signer and the minors) must be on the ticket: the headcount of the booking
     * is the signer plus the minors.
     */
    public function markWaiverSigned(int $participants): void
    {
        if ($participants !== $this->headcount) {
            throw AdventureParksException::refuse('headcount_mismatch', 'The waiver covers '.$participants.' people but the ticket has '.$this->headcount.'.', [
                'expected' => $this->headcount,
                'given' => $participants,
            ]);
        }

        $this->transitionTo(TicketStatus::WaiverSigned);
    }

    /**
     * The desk may hand out equipment only after the waiver: a signed waiver that covers every participant.
     *
     * @param  int  $participantCount  participants stored for the ticket
     */
    public function assertWaiverCovers(int $participantCount): void
    {
        $signed = in_array($this->statusEnum(), [TicketStatus::WaiverSigned, TicketStatus::Equipped, TicketStatus::OnCourse], true);

        if (! $signed || $participantCount < $this->headcount) {
            throw AdventureParksException::refuse('waiver_missing', 'A signed waiver covering every participant is required before the equipment is handed out.', [
                'booking_code' => $this->booking_code,
            ]);
        }
    }

    public function equip(): void
    {
        $this->transitionTo(TicketStatus::Equipped);
    }

    public function startCourse(): void
    {
        $this->transitionTo(TicketStatus::OnCourse);
    }

    public function markReturned(): void
    {
        $this->transitionTo(TicketStatus::Returned);
    }

    public function markNoShow(): void
    {
        $this->transitionTo(TicketStatus::NoShow);
    }
}
