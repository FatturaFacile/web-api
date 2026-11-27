<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Green care: gardening and green maintenance vertical (Mod 126 / Issue #372)
    |--------------------------------------------------------------------------
    | Disk of the before/after photos of the interventions. Private: files are served only through authenticated endpoints.
    */
    'disk' => env('GREEN_CARE_DISK', 'local'),

    // Largest photo accepted, in kilobytes.
    'max_photo_kb' => (int) env('GREEN_CARE_MAX_PHOTO_KB', 10240),

    /*
    | Weather outlook (Open-Meteo: no API key). A forecast is cached for `cache_minutes`; when the provider is not reachable the last
    | known forecast (kept for `stale_hours`) is used, and when there is none the route simply stays without an alert.
    */
    'weather' => [
        'provider_url' => env('GREEN_CARE_WEATHER_URL', 'https://api.open-meteo.com/v1/forecast'),
        'timeout_seconds' => (int) env('GREEN_CARE_WEATHER_TIMEOUT', 6),
        'cache_minutes' => (int) env('GREEN_CARE_WEATHER_CACHE_MINUTES', 180),
        'stale_hours' => (int) env('GREEN_CARE_WEATHER_STALE_HOURS', 168),
        // Outdoor work (mowing, spraying) is postponed above these precipitation / wind values or below this minimum temperature.
        'rain_threshold_mm' => (float) env('GREEN_CARE_RAIN_THRESHOLD_MM', 5),
        'frost_threshold_celsius' => (float) env('GREEN_CARE_FROST_THRESHOLD_C', 0),
        'wind_threshold_kmh' => (float) env('GREEN_CARE_WIND_THRESHOLD_KMH', 40),
    ],

    /*
    | Route planning. Travel is estimated from the straight line distance multiplied by a road factor.
    */
    'routing' => [
        'average_speed_kmh' => 35,
        'road_factor' => 1.3,
        'setup_minutes_per_stop' => 15,
        'working_days' => [1, 2, 3, 4, 5],
        'reschedule_horizon_days' => 7,
    ],

    /*
    | Withholding of the Italian condominiums: the condominium withholds 4% on the contracts of works and services (Art. 25-ter DPR 600/73).
    | `type` is the TipoRitenuta and `reason` the CausalePagamento of the FatturaPA block DatiRitenuta.
    */
    'condominium_withholding' => [
        'percent' => 4,
        'type' => env('GREEN_CARE_WITHHOLDING_TYPE', 'RT02'),
        'reason' => env('GREEN_CARE_WITHHOLDING_REASON', 'A'),
        'legal_basis' => 'Art. 25-ter DPR 600/73',
    ],

    // VAT rate of the services by jurisdiction (standard rates: Italy 22%, Switzerland 8.1%).
    'vat_rates' => ['IT' => 22.0, 'CH' => 8.1],

    // Hourly rates (CHF) of the JardinSuisse "regia" tariff, by role, used when a crew member has no rate of its own.
    'regia_rates_chf' => [
        'gardener' => 68.0,
        'foreman' => 78.0,
        'apprentice' => 38.0,
        'machine_operator' => 92.0,
    ],

    // Alerts on the validity of the operator licenses: days before the expiry shown on the dashboard.
    'license_expiry_alert_days' => 60,
];
