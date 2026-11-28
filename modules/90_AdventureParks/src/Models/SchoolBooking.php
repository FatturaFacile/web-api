<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\AdventureParks\Domain\Enums\SchoolBookingStatus;
use Modules\AdventureParks\Exceptions\AdventureParksException;

/**
 * A school trip booked by a public institute: workshops, picnic seats, free accompanying teachers and the electronic invoice to the
 * public administration (split payment, CIG / CUP, IPA office code). The aggregate keeps the life cycle DRAFT -> CONFIRMED ->
 * COMPLETED -> INVOICED and refuses to change an invoiced booking.
 *
 * @property string|null $customer_contact_id
 * @property string $institute_name
 * @property string|null $mechanographic_code
 * @property string $ipa_office_code
 * @property string|null $cig_code
 * @property string|null $cup_code
 * @property bool $split_payment
 * @property Carbon $visit_date
 * @property string $start_time
 * @property string $end_time
 * @property bool $picnic_reserved
 * @property int $paying_students
 * @property int $disabled_students
 * @property int $accompanying_teachers
 * @property int $free_teachers
 * @property int $charged_teachers
 * @property int $picnic_seats
 * @property string $total_amount
 * @property string $vat_rate
 * @property string $status
 * @property string|null $fattura_invoice_id
 * @property string|null $fattura_number
 * @property Carbon|null $invoiced_at
 */
final class SchoolBooking extends BaseEnterpriseModel
{
    protected $table = 'adv_school_bookings';

    protected function casts(): array
    {
        return [
            'split_payment' => 'boolean',
            'picnic_reserved' => 'boolean',
            'visit_date' => 'date',
            'paying_students' => 'integer',
            'disabled_students' => 'integer',
            'accompanying_teachers' => 'integer',
            'free_teachers' => 'integer',
            'charged_teachers' => 'integer',
            'picnic_seats' => 'integer',
            'total_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'invoiced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SchoolBookingWorkshop, $this>
     */
    public function workshopLines(): HasMany
    {
        return $this->hasMany(SchoolBookingWorkshop::class, 'school_booking_id');
    }

    public function statusEnum(): SchoolBookingStatus
    {
        return SchoolBookingStatus::from($this->status);
    }

    /**
     * Stores the result of the quote (free and charged teachers, picnic seats, total).
     *
     * @param  array{free_teachers: int, charged_teachers: int, picnic_seats: int, total_amount: string}  $quote
     */
    public function applyQuote(array $quote): void
    {
        $this->free_teachers = $quote['free_teachers'];
        $this->charged_teachers = $quote['charged_teachers'];
        $this->picnic_seats = $quote['picnic_seats'];
        $this->total_amount = $quote['total_amount'];
    }

    public function assertEditable(): void
    {
        if ($this->statusEnum() === SchoolBookingStatus::Invoiced) {
            throw AdventureParksException::refuse('already_invoiced', 'An invoiced booking cannot be changed.');
        }
    }

    public function confirm(): void
    {
        if ($this->statusEnum() !== SchoolBookingStatus::Draft) {
            throw AdventureParksException::refuse('invalid_transition', 'Only a draft booking can be confirmed.', ['from' => $this->status, 'to' => SchoolBookingStatus::Confirmed->value]);
        }

        $this->status = SchoolBookingStatus::Confirmed->value;
    }

    public function complete(): void
    {
        if ($this->statusEnum() !== SchoolBookingStatus::Confirmed) {
            throw AdventureParksException::refuse('invalid_transition', 'Only a confirmed booking can be completed.', ['from' => $this->status, 'to' => SchoolBookingStatus::Completed->value]);
        }

        $this->status = SchoolBookingStatus::Completed->value;
    }

    /**
     * The invoice can be generated once for a confirmed (or completed) booking.
     */
    public function assertInvoiceable(): void
    {
        $status = $this->statusEnum();

        if ($status === SchoolBookingStatus::Invoiced) {
            throw AdventureParksException::refuse('already_invoiced', 'The booking has already been invoiced.', ['invoice_id' => $this->fattura_invoice_id]);
        }

        if ($status === SchoolBookingStatus::Draft) {
            throw AdventureParksException::refuse('booking_not_confirmed', 'Confirm the booking before invoicing it.');
        }
    }

    public function markInvoiced(string $invoiceId, string $invoiceNumber, CarbonInterface $at): void
    {
        $this->assertInvoiceable();

        $this->status = SchoolBookingStatus::Invoiced->value;
        $this->fattura_invoice_id = $invoiceId;
        $this->fattura_number = $invoiceNumber;
        $this->invoiced_at = $at;
    }
}
