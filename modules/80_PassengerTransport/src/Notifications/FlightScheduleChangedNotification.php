<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Notifications;

use Illuminate\Notifications\Notification;
use Modules\PassengerTransport\Events\FlightScheduleAdjusted;

/**
 * Database notification to the dispatchers and the driver when a flight changes the pickup of a transfer. The text is built by the
 * client from the key (passengerTransport.notification.<key>) in the language of the user.
 */
final class FlightScheduleChangedNotification extends Notification
{
    public function __construct(private readonly FlightScheduleAdjusted $event) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $key = match (true) {
            $this->event->flightStatus === 'cancelled' => 'flight_cancelled',
            $this->event->conflictCodes !== [] => 'pickup_moved_with_conflict',
            default => 'pickup_moved',
        };

        return [
            'key' => $key,
            'module' => 'passenger_transport',
            'booking_id' => $this->event->bookingId,
            'booking_code' => $this->event->bookingCode,
            'flight_status' => $this->event->flightStatus,
            'delay_minutes' => $this->event->delayMinutes,
            'previous_pickup' => $this->event->previousPickup,
            'new_pickup' => $this->event->newPickup,
            'conflicts' => $this->event->conflictCodes,
        ];
    }
}
