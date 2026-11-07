<?php

declare(strict_types=1);

return [

    /*
    | Days the personal data of a visitor are kept after the last visit ended (or was due, for visits that never took
    | place). A company can shorten or extend it with the "visitors.retention_days" preference, within the bounds below.
    */
    'retention_days' => (int) env('VISITORS_RETENTION_DAYS', 90),

    'retention_min_days' => 1,

    'retention_max_days' => 3650,

];
