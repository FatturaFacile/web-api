<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Electronic document networks
|--------------------------------------------------------------------------
|
| eBill (SIX), Peppol and NSO need a contract with a provider, which does not exist yet. `refused` is the default and
| answers every call with an error. `local` is a simulator for development and never runs in production.
|
*/

return [
    'drivers' => [
        'ebill' => env('EDOCUMENT_EBILL_DRIVER', 'refused'),
        'peppol' => env('EDOCUMENT_PEPPOL_DRIVER', 'refused'),
        'nso' => env('EDOCUMENT_NSO_DRIVER', 'refused'),
    ],
];
