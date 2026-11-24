<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Reminder of an upcoming appointment, sent to the customer contact and to the assigned staff.
 */
class AppointmentReminderMail extends Mailable
{
    public function __construct(public readonly Appointment $appointment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.appointment_reminder.subject', [
            'number' => (string) $this->appointment->appointment_number,
            'date' => $this->appointment->starts_at?->format('d/m/Y'),
        ]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.appointment-reminder');
    }
}
