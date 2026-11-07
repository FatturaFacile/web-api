<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI provider boundaries
|--------------------------------------------------------------------------
|
| Document extraction and the assistant default to the local driver, which sends nothing outside the installation. Any
| driver missing from `local_drivers` counts as external and runs only for a tenant that enabled external providers.
|
*/

return [
    'extraction_driver' => env('AI_EXTRACTION_DRIVER', 'local'),
    'assistant_driver' => env('AI_ASSISTANT_DRIVER', 'local'),
    'local_drivers' => ['local'],
];
