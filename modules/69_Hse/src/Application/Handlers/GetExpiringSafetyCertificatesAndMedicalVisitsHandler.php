<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use Carbon\CarbonImmutable;
use Modules\Hse\Application\Queries\GetExpiringSafetyCertificatesAndMedicalVisitsQuery;
use Modules\Hse\Domain\Enums\PpeDeliveryStatus;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;
use Modules\Hse\Infrastructure\Models\OccupationalMedicalVisit;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;

/**
 * Lists the certificates, the next medical visits and the PPE replacements due within the horizon (or already overdue),
 * one entry per deadline. A certificate superseded by a newer one of the same type, a visit followed by a later visit
 * and a PPE item replaced by a newer hand-over no longer count.
 */
final class GetExpiringSafetyCertificatesAndMedicalVisitsHandler
{
    /**
     * @return list<array{subject_type: string, subject_id: string, employee_id: string, label: string, due_date: string, days_left: int}>
     */
    public function handle(GetExpiringSafetyCertificatesAndMedicalVisitsQuery $query): array
    {
        $today = $query->today->startOfDay();
        $limit = $today->addDays($query->withinDays)->toDateString();
        $items = [];

        $certificates = EmployeeSafetyCertificate::query()->where('tenant_id', $query->tenantId)->whereNull('revoked_at')->get();
        $latestExpiry = $certificates->groupBy(fn (EmployeeSafetyCertificate $c): string => $c->employee_id.'|'.$c->certificate_type->value)
            ->map(fn ($group): string => (string) $group->max(fn (EmployeeSafetyCertificate $c): string => $c->expires_at?->toDateString() ?? '9999-12-31'));

        foreach ($certificates as $certificate) {
            $due = $certificate->expires_at?->toDateString();
            $key = $certificate->employee_id.'|'.$certificate->certificate_type->value;

            if ($due === null || $due > $limit || $latestExpiry[$key] > $due) {
                continue;
            }

            $items[] = $this->item('certificate', (string) $certificate->id, (string) $certificate->employee_id, $certificate->certificate_type->value, $due, $today);
        }

        $visits = OccupationalMedicalVisit::query()->where('tenant_id', $query->tenantId)->orderByDesc('visit_date')->orderByDesc('created_at')->get()->unique('employee_id');

        foreach ($visits as $visit) {
            $due = $visit->next_visit_due_date?->toDateString();

            if ($due !== null && $due <= $limit) {
                $items[] = $this->item('medical_visit', (string) $visit->id, (string) $visit->employee_id, 'medical_visit', $due, $today);
            }
        }

        $records = PpeDistributionRecord::query()->where('tenant_id', $query->tenantId)->where('delivery_status', PpeDeliveryStatus::DeliveredSigned->value)
            ->whereNotNull('replacement_due_at')->orderByDesc('handed_over_at')->get()->unique(fn (PpeDistributionRecord $r): string => $r->employee_id.'|'.$r->product_id);

        foreach ($records as $record) {
            $due = $record->replacement_due_at?->toDateString();

            if ($due !== null && $due <= $limit) {
                $items[] = $this->item('ppe', (string) $record->id, (string) $record->employee_id, (string) $record->product_id, $due, $today);
            }
        }

        usort($items, static fn (array $a, array $b): int => $a['days_left'] <=> $b['days_left']);

        return $items;
    }

    /**
     * @return array{subject_type: string, subject_id: string, employee_id: string, label: string, due_date: string, days_left: int}
     */
    private function item(string $type, string $id, string $employeeId, string $label, string $due, CarbonImmutable $today): array
    {
        return [
            'subject_type' => $type, 'subject_id' => $id, 'employee_id' => $employeeId, 'label' => $label, 'due_date' => $due,
            'days_left' => (int) $today->diffInDays(CarbonImmutable::parse($due)->startOfDay(), false),
        ];
    }
}
