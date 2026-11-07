<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Appointment reminders
|--------------------------------------------------------------------------
|
| Every fifteen minutes the scheduler queues a reminder for the appointments that start within the lead time and have
| none yet; the customer contact and the assigned technicians receive it by e-mail.
|
*/

return [
    'reminder_lead_minutes' => (int) env('APPOINTMENT_REMINDER_LEAD_MINUTES', 1440),
];
