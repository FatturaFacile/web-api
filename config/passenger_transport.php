<?php

declare(strict_types=1);

return [
    /*
    | Routing used to split the kilometres of a trip by country: haversine (deterministic, no key), osrm (OSRM or a Valhalla server
    | exposing the OSRM API) or google (Distance Matrix). Every external provider falls back to the deterministic estimate on failure.
    */
    'routing' => [
        'driver' => env('PASSENGER_TRANSPORT_ROUTING_DRIVER', 'haversine'),
        'osrm_url' => env('PASSENGER_TRANSPORT_OSRM_URL', 'https://router.project-osrm.org'),
        'google_key' => env('PASSENGER_TRANSPORT_GOOGLE_MAPS_KEY'),
        'road_factor' => (float) env('PASSENGER_TRANSPORT_ROAD_FACTOR', 1.3),
        'average_speed_kmh' => (float) env('PASSENGER_TRANSPORT_AVERAGE_SPEED_KMH', 60),
    ],

    /*
    | Flight status provider: simulated (deterministic, for development and tests) or aviationstack.
    | buffer_minutes: time between the landing and the pickup; threshold_minutes: smaller drifts do not move the pickup.
    */
    'flights' => [
        'driver' => env('PASSENGER_TRANSPORT_FLIGHT_DRIVER', 'simulated'),
        'aviationstack_key' => env('PASSENGER_TRANSPORT_AVIATIONSTACK_KEY'),
        'buffer_minutes' => (int) env('PASSENGER_TRANSPORT_FLIGHT_BUFFER_MINUTES', 30),
        'threshold_minutes' => (int) env('PASSENGER_TRANSPORT_FLIGHT_THRESHOLD_MINUTES', 10),
        'track_window_hours' => (int) env('PASSENGER_TRANSPORT_FLIGHT_TRACK_WINDOW_HOURS', 12),
        'turnaround_minutes' => (int) env('PASSENGER_TRANSPORT_TURNAROUND_MINUTES', 30),
    ],

    /*
    | VAT of the fares. IT: 10 % on transport of persons (Tabella A, Parte III, n. 127-novies DPR 633/72); the natura code of the
    | foreign leg of a cross-border fare (N2.1 outside the territory, or N3.4 where the company treats it under Art. 9) and the
    | urban perimeter of the Art. 10 n. 14 exemption. CH: standard MWST rate.
    */
    'vat' => [
        'it_rate' => env('PASSENGER_TRANSPORT_VAT_IT_RATE', '10'),
        'it_foreign_nature' => env('PASSENGER_TRANSPORT_VAT_IT_FOREIGN_NATURE', 'N2.1'),
        'it_urban_perimeter_km' => env('PASSENGER_TRANSPORT_VAT_IT_URBAN_KM', '50'),
        'ch_standard_rate' => env('PASSENGER_TRANSPORT_VAT_CH_RATE', '8.1'),
    ],

    /*
    | OLR 2 / ARV 2 thresholds in minutes (light passenger vehicles of professional drivers), as set by the operating rules of the
    | company; OLR 1 / EC 561 limits are fixed by the regulation.
    */
    'olr2' => [
        'first_break_after' => (int) env('PASSENGER_TRANSPORT_OLR2_FIRST_BREAK_AFTER', 120),
        'first_break_minutes' => (int) env('PASSENGER_TRANSPORT_OLR2_FIRST_BREAK_MINUTES', 15),
        'second_break_after' => (int) env('PASSENGER_TRANSPORT_OLR2_SECOND_BREAK_AFTER', 210),
        'second_break_minutes' => (int) env('PASSENGER_TRANSPORT_OLR2_SECOND_BREAK_MINUTES', 30),
        'daily_limit' => (int) env('PASSENGER_TRANSPORT_OLR2_DAILY_LIMIT', 540),
        'daily_rest' => (int) env('PASSENGER_TRANSPORT_OLR2_DAILY_REST', 660),
    ],

    /*
    | Minutes of garage run added to the driving time of a trip (departure from and return to the garage).
    */
    'garage_run_minutes' => (int) env('PASSENGER_TRANSPORT_GARAGE_RUN_MINUTES', 20),

    /*
    | Disk of the archived .DDD files, welcome-sign logos and service sheet PDFs (under the tenant folder).
    */
    'storage_disk' => env('PASSENGER_TRANSPORT_DISK', 'local'),
    'max_ddd_kilobytes' => (int) env('PASSENGER_TRANSPORT_MAX_DDD_KB', 5120),

    /*
    | Revenue account codes proposed on invoice lines (optional; null leaves the default of the invoicing module).
    */
    'revenue_account_code' => env('PASSENGER_TRANSPORT_REVENUE_ACCOUNT'),
];
