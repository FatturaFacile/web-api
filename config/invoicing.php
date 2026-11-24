<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payment reminders (richiami)
    |--------------------------------------------------------------------------
    */
    'reminders' => [
        // Days after the due date before the first reminder is sent.
        'grace_days' => (int) env('REMINDER_GRACE_DAYS', 0),
        // Minimum days between two reminders on the same invoice.
        'interval_days' => (int) env('REMINDER_INTERVAL_DAYS', 15),
        // Highest escalation level (1 = gentle, 2 = formal, 3 = final).
        'max_level' => (int) env('REMINDER_MAX_LEVEL', 3),
    ],

];
