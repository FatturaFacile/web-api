<?php

declare(strict_types=1);

return [
    // Appends the writes of the four domains to the bi-temporal event store (Mod 354). Turn off only for a tenant restore or a bulk import
    // that is backfilled afterwards with `php artisan bitemporal:backfill-and-snapshot`.
    'enabled' => (bool) env('TIME_TRAVEL_ENABLED', true),
];
