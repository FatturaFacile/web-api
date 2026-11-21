<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Hse\Application\Handlers\GetExpiringSafetyCertificatesAndMedicalVisitsHandler;
use Modules\Hse\Application\Queries\GetExpiringSafetyCertificatesAndMedicalVisitsQuery;
use Modules\Hse\Domain\Contracts\HseNotificationGatewayInterface;
use Modules\Hse\Domain\Enums\CertificateStatus;
use Modules\Hse\Domain\Events\SafetyCertificateExpiredEvent;
use Modules\Hse\Domain\Events\SafetyCertificateExpiringEvent;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;
use Modules\Hse\Infrastructure\Models\HseExpirationAlert;

/**
 * The daily expiration scan of a tenant. A deadline raises an alert at exactly 60, 30 and 7 days before it, and every
 * day once it is overdue; each alert is logged, so running the scan twice on the same day sends nothing twice.
 */
final class HseExpirationScanner
{
    /** Days before the deadline at which an alert goes out. */
    public const array Thresholds = [60, 30, 7];

    /** Threshold stored for the daily escalation of an overdue deadline. */
    public const int Overdue = -1;

    public function __construct(
        private readonly GetExpiringSafetyCertificatesAndMedicalVisitsHandler $expiring,
        private readonly HseNotificationGatewayInterface $notifications,
        private readonly HseRecipientResolver $recipients,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @return int the number of alerts sent
     */
    public function scan(string $tenantId, CarbonImmutable $today): int
    {
        $sent = 0;

        foreach ($this->expiring->handle(new GetExpiringSafetyCertificatesAndMedicalVisitsQuery($tenantId, $today, max(self::Thresholds))) as $item) {
            $daysLeft = $item['days_left'];
            $threshold = $daysLeft < 0 ? self::Overdue : (in_array($daysLeft, self::Thresholds, true) ? $daysLeft : null);

            if ($threshold === null) {
                $this->syncCertificateStatus($item, $today);

                continue;
            }

            // Positive thresholds are keyed on the deadline (they fire once per deadline), the overdue one on the day.
            $alertDate = $threshold === self::Overdue ? $today->toDateString() : $item['due_date'];

            try {
                $alert = HseExpirationAlert::create([
                    'tenant_id' => $tenantId, 'subject_type' => $item['subject_type'], 'subject_id' => $item['subject_id'],
                    'threshold_days' => $threshold, 'alert_date' => $alertDate,
                ]);
            } catch (UniqueConstraintViolationException) {
                $this->syncCertificateStatus($item, $today);

                continue;
            }

            $count = $this->notifications->notify(
                $tenantId,
                $this->recipients->forEmployee($tenantId, $item['employee_id']),
                $threshold === self::Overdue ? 'hse_deadline_overdue' : 'hse_deadline_expiring',
                [
                    'subject_type' => $item['subject_type'], 'subject_id' => $item['subject_id'], 'employee_id' => $item['employee_id'],
                    'label' => $item['label'], 'due_date' => $item['due_date'], 'days_left' => $daysLeft,
                ],
            );
            $alert->update(['recipients_count' => $count]);
            $sent++;

            $this->syncCertificateStatus($item, $today);
            $this->dispatchCertificateEvent($tenantId, $item, $daysLeft);
        }

        return $sent;
    }

    /**
     * @param  array{subject_type: string, subject_id: string, employee_id: string, label: string, due_date: string, days_left: int}  $item
     */
    private function syncCertificateStatus(array $item, CarbonImmutable $today): void
    {
        if ($item['subject_type'] !== 'certificate') {
            return;
        }

        $certificate = EmployeeSafetyCertificate::query()->find($item['subject_id']);

        if ($certificate !== null && $certificate->status !== CertificateStatus::Revoked) {
            $status = $certificate->statusAt($today);

            if ($status !== $certificate->status) {
                $certificate->update(['status' => $status]);
            }
        }
    }

    /**
     * @param  array{subject_type: string, subject_id: string, employee_id: string, label: string, due_date: string, days_left: int}  $item
     */
    private function dispatchCertificateEvent(string $tenantId, array $item, int $daysLeft): void
    {
        if ($item['subject_type'] !== 'certificate') {
            return;
        }

        $this->events->dispatch($daysLeft < 0
            ? new SafetyCertificateExpiredEvent($tenantId, $item['subject_id'], $item['employee_id'], $item['label'], $item['due_date'])
            : new SafetyCertificateExpiringEvent($tenantId, $item['subject_id'], $item['employee_id'], $item['label'], $item['due_date'], $daysLeft));
    }
}
