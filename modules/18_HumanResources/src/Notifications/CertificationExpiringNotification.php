<?php

declare(strict_types=1);

namespace Modules\HumanResources\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the employee and the HR managers that a certification or a mandatory training (a safety course, for example)
 * expires soon or has expired. Stored as a database notification of each user.
 */
final class CertificationExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $certificationId,
        public readonly string $employeeId,
        public readonly string $employeeName,
        public readonly string $certificationCode,
        public readonly string $certificationName,
        public readonly string $expiryDate,
        public readonly int $daysUntilExpiry,
    ) {}

    /**
     * Delivery channels: the database notifications of the user.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Data stored with the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'activity' => $this->daysUntilExpiry < 0 ? 'hr_certification_expired' : 'hr_certification_expiring',
            'certification_id' => $this->certificationId,
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
            'certification_code' => $this->certificationCode,
            'certification_name' => $this->certificationName,
            'expiry_date' => $this->expiryDate,
            'days_until_expiry' => $this->daysUntilExpiry,
        ];
    }
}
