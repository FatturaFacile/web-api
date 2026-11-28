<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Olive oil mills and grain mills (Mod 297)
|--------------------------------------------------------------------------
| The technical and statutory constants of the vertical. They are working assumptions to be reviewed every campaign with the mill's
| consultant: the olive oil density, the cold extraction limit, the MASAF thresholds, the six day SIAN deadline and the agronomic
| spreading limit of the vegetation water (L. 574/1996).
*/
return [
    // Standard density of olive oil (kg per litre) used to convert between weight and volume.
    'density_kg_per_l' => 0.916,

    // Malaxation at or below this temperature (Celsius) qualifies as cold extraction ("estratto a freddo").
    'cold_extraction_max_c' => 27.0,

    // Days within which a SIAN operation must be recorded and transmitted (telematic oil register, MASAF).
    'sian_deadline_days' => 6,

    // An untransmitted operation is flagged as "due soon" when at most this many days are left.
    'sian_alert_days' => 2,

    // Minutes before the milling slot at which the farmer receives the hopper SMS reminder.
    'sms_lead_minutes' => 45,

    // Best before date of a bottled lot, months after the bottling day.
    'best_before_months' => 18,

    // Regulatory limits of the lab parameters used to grade the oil (Reg. CEE 2568/91 and following).
    'thresholds' => [
        'EVO' => ['acidity_max' => 0.8, 'peroxides_max' => 20.0],
        'VERGINE' => ['acidity_max' => 2.0, 'peroxides_max' => 20.0],
    ],

    // Agronomic spreading of the vegetation water: cubic metres per hectare per year.
    'vegetation_water_max_m3_per_ha' => 50.0,

    // Default VAT rates (percent) proposed for the milling service (ordinary rate) and for the oil bought back (agricultural product).
    'vat' => [
        'milling_service' => 22.0,
        'oil_purchase' => 4.0,
    ],

    'sian' => [
        // "sandbox" acknowledges every file locally; "http" posts the XML to the configured web service.
        'driver' => env('OLIVE_MILL_SIAN_DRIVER', 'sandbox'),
        'endpoint' => env('OLIVE_MILL_SIAN_ENDPOINT'),
        'token' => env('OLIVE_MILL_SIAN_TOKEN'),
        'timeout' => 20,
    ],
];
