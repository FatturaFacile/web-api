<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Models\Employee;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\BorderCrossing;
use Modules\PassengerTransport\Domain\Enums\ExchangeDirection;
use Modules\PassengerTransport\Domain\Enums\FlightStatus;
use Modules\PassengerTransport\Domain\Enums\ServiceType;

/**
 * A transfer, hourly disposal, coach tour, school bus run or non-emergency medical transport, from the request to the invoice.
 *
 * @property string $booking_code
 * @property ServiceType $service_type
 * @property BookingStatus $status
 * @property string|null $ordering_customer_id
 * @property string|null $b2b_concierge_user_id
 * @property string|null $hotel_contract_id
 * @property string|null $school_contract_id
 * @property string|null $lead_passenger_name
 * @property string|null $lead_passenger_phone
 * @property string|null $vip_welcome_sign_text
 * @property string|null $vip_welcome_logo_path
 * @property int $passengers_count
 * @property int $luggage_count
 * @property array<string, int>|null $child_seats_spec
 * @property string|null $accessibility_notes
 * @property string|null $flight_or_train_number
 * @property string|null $flight_carrier_iata
 * @property string|null $arrival_airport_iata
 * @property Carbon|null $scheduled_flight_arrival_at
 * @property Carbon|null $estimated_flight_arrival_at
 * @property FlightStatus|null $flight_status
 * @property int $flight_delay_minutes
 * @property Carbon|null $flight_checked_at
 * @property Carbon $pickup_datetime
 * @property Carbon|null $estimated_dropoff_datetime
 * @property string $pickup_address
 * @property string|null $pickup_lat
 * @property string|null $pickup_lng
 * @property string $pickup_country_code
 * @property string $dropoff_address
 * @property string|null $dropoff_lat
 * @property string|null $dropoff_lng
 * @property string $dropoff_country_code
 * @property list<array<string, mixed>>|null $waypoints
 * @property string $total_distance_km
 * @property string $it_territory_km
 * @property string $ch_territory_km
 * @property string $other_eu_territory_km
 * @property BorderCrossing $border_crossing_point
 * @property string $agreed_price_net
 * @property string $currency
 * @property bool $vat_exemption_art23
 * @property bool $municipal_urban_service
 * @property string|null $hotel_concierge_commission_rate
 * @property string $hotel_concierge_commission_amount
 * @property ExchangeDirection $exchange_direction
 * @property string|null $subcontracted_carrier_id
 * @property string $subcontractor_payout_amount
 * @property string $assignor_commission_amount
 * @property string|null $assigned_vehicle_id
 * @property string|null $assigned_driver_id
 * @property string|null $invoice_id
 */
final class PassengerBooking extends BaseEnterpriseModel
{
    protected $table = 'ptr_bookings';

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'status' => BookingStatus::class,
            'child_seats_spec' => 'array',
            'waypoints' => 'array',
            'scheduled_flight_arrival_at' => 'datetime',
            'estimated_flight_arrival_at' => 'datetime',
            'flight_status' => FlightStatus::class,
            'flight_delay_minutes' => 'integer',
            'flight_checked_at' => 'datetime',
            'pickup_datetime' => 'datetime',
            'estimated_dropoff_datetime' => 'datetime',
            'pickup_lat' => 'decimal:7',
            'pickup_lng' => 'decimal:7',
            'dropoff_lat' => 'decimal:7',
            'dropoff_lng' => 'decimal:7',
            'total_distance_km' => 'decimal:2',
            'it_territory_km' => 'decimal:2',
            'ch_territory_km' => 'decimal:2',
            'other_eu_territory_km' => 'decimal:2',
            'border_crossing_point' => BorderCrossing::class,
            'agreed_price_net' => 'decimal:2',
            'vat_exemption_art23' => 'boolean',
            'municipal_urban_service' => 'boolean',
            'hotel_concierge_commission_rate' => 'decimal:3',
            'hotel_concierge_commission_amount' => 'decimal:2',
            'exchange_direction' => ExchangeDirection::class,
            'subcontractor_payout_amount' => 'decimal:2',
            'assignor_commission_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function orderingCustomer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'ordering_customer_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'subcontracted_carrier_id');
    }

    /**
     * @return BelongsTo<FleetVehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'assigned_vehicle_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_driver_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    /**
     * @return HasMany<ServiceSheet, $this>
     */
    public function serviceSheets(): HasMany
    {
        return $this->hasMany(ServiceSheet::class, 'booking_id');
    }

    public function isCrossBorder(): bool
    {
        return $this->pickup_country_code !== $this->dropoff_country_code;
    }

    /**
     * The booking is still open to changes of its route, fare and assignment.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [BookingStatus::Draft, BookingStatus::Confirmed, BookingStatus::Assigned], true);
    }
}
