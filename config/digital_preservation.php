<?php

declare(strict_types=1);

return [
    /*
    | Storage disk of the built-in WORM vault. Production should point it at an object-lock enabled bucket.
    */
    'disk' => env('PRESERVATION_WORM_DISK', 'preservation_worm'),

    /*
    | PDF/A conversion driver: ghostscript, libreoffice or simulated (test double, NOT real PDF/A conformance).
    */
    'pdfa_driver' => env('PRESERVATION_PDFA_DRIVER', 'ghostscript'),
    'ghostscript_binary' => env('PRESERVATION_GHOSTSCRIPT_BINARY', 'gs'),
    'libreoffice_binary' => env('PRESERVATION_LIBREOFFICE_BINARY', 'soffice'),

    /*
    | Without a configured RFC 3161 authority a local, non-qualified test authority signs the time stamp. Disable it in
    | production to force a real TSA.
    */
    'allow_local_tsa' => (bool) env('PRESERVATION_ALLOW_LOCAL_TSA', true),

    /*
    | Statutory minimum, in years (Italian CAD/AgID and Swiss CO Art. 958f).
    */
    'minimum_retention_years' => 10,

    /*
    | Retries of the HTTP calls to a conservator: attempts and wait in milliseconds.
    */
    'connector_retries' => 3,
    'connector_retry_wait_ms' => 200,
];
