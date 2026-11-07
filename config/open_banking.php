<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Open banking (PSD2)
|--------------------------------------------------------------------------
|
| A real connection needs a licensed provider or a bank agreement, which do not exist yet. `refused` answers every call
| with an error. `local` is a simulator for development and never runs in production.
|
*/

return [
    'driver' => env('OPEN_BANKING_DRIVER', 'refused'),
];
