<?php

declare(strict_types=1);

return [

    /*
    | Booking defaults, used for a kind of space the company did not configure in "Booking rules". A rule of the
    | company replaces the default of its kind field by field.
    */
    'booking_defaults' => [
        'max_bookings_per_user_per_day' => null,
        'max_minutes_per_booking' => 720,
        'max_advance_days' => 90,
        'checkin_grace_minutes' => 15,
        'requires_checkin' => false,
        'kinds' => [
            'desk' => ['max_bookings_per_user_per_day' => 1, 'requires_checkin' => true, 'max_minutes_per_booking' => 720],
            'parking' => ['max_bookings_per_user_per_day' => 1, 'requires_checkin' => false, 'max_minutes_per_booking' => 1440],
            'meeting_room' => ['max_bookings_per_user_per_day' => null, 'requires_checkin' => true, 'max_minutes_per_booking' => 480],
        ],
    ],

    /*
    | Minutes before the start of a booking from which the user can already check in.
    */
    'checkin_early_minutes' => 15,

    /*
    | Upper bound of the bookings a recurring series can generate, and of the span of a series in days.
    */
    'max_series_occurrences' => 120,

    'max_series_span_days' => 366,

    /*
    | Hours to resolve a service request, by priority (calendar hours from the moment it was raised).
    */
    'sla_hours' => [
        'urgent' => 4,
        'high' => 8,
        'normal' => 24,
        'low' => 72,
    ],

    /*
    | Inspections generated at once by a template that fell behind (the scheduler was off for a long time).
    */
    'max_generated_inspections_per_run' => 31,

];
