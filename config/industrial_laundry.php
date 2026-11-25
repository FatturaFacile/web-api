<?php

declare(strict_types=1);

return [
    /*
    | RFID UHF tag read filter. A read weaker than min_rssi_dbm is out of range (a tag of the next cage or of the aisle), an EPC
    | outside the hexadecimal length bounds is spurious (a 96-bit EPC has 24 hex characters).
    */
    'rfid' => [
        'min_rssi_dbm' => (float) env('LAUNDRY_RFID_MIN_RSSI_DBM', -75),
        'min_epc_hex_length' => (int) env('LAUNDRY_RFID_MIN_EPC_HEX', 8),
        'max_epc_hex_length' => (int) env('LAUNDRY_RFID_MAX_EPC_HEX', 64),
    ],

    /*
    | Roll cages: the heaviest gross weight a cage may be weighed at when the cage has no limit of its own.
    */
    'roll_cage' => [
        'max_gross_weight_kg' => env('LAUNDRY_ROLL_CAGE_MAX_GROSS_KG', '600'),
    ],

    /*
    | PPE wash limit (D.Lgs. 81/08, EN ISO 20471 and the manufacturer limit): the share of the certified cycles that raises the
    | early warning.
    */
    'ppe' => [
        'warning_ratio' => env('LAUNDRY_PPE_WARNING_RATIO', '0.9'),
    ],

    /*
    | UNI EN 14065 RABC: the highest microbial count (CFU per 25 cm2 of textile) a disinfected batch may carry.
    */
    'rabc' => [
        'max_cfu_per_25cm2' => env('LAUNDRY_RABC_MAX_CFU', '20'),
    ],

    /*
    | Industrial water discharge report: litres of water discharged per kg of linen treated by the tunnel.
    */
    'discharge' => [
        'water_litres_per_kg' => env('LAUNDRY_DISCHARGE_LITRES_PER_KG', '8'),
    ],

    /*
    | Standard VAT rate proposed on the contracts, by jurisdiction (percent).
    */
    'tax_rates' => [
        'IT' => env('LAUNDRY_TAX_RATE_IT', '22'),
        'CH' => env('LAUNDRY_TAX_RATE_CH', '8.1'),
    ],

    /*
    | Days after which a garment still at the customer counts as dormant when the contract does not set its own threshold.
    */
    'dormant_days' => (int) env('LAUNDRY_DORMANT_DAYS', 60),
];
