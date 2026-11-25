<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\TenderManagement\Domain\Enums\AlertSeverity;
use Modules\TenderManagement\Domain\Enums\NoticeStatus;
use Modules\TenderManagement\Domain\Enums\ParticipationStatus;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Events\TenderAlertRaised;
use Modules\TenderManagement\Models\TenderAlert;
use Modules\TenderManagement\Models\TenderNotice;

/**
 * The multi-level alert engine. Submission and clarification deadlines of the tenders still being worked on raise one alert per
 * milestone (7 days, 72 h, 24 h, 4 h before); vault documents raise alerts as their expiry approaches (90/60/30/7 days and the
 * day it lapses), the Swiss debt-enforcement extract on its rolling three month window and the SOA attestation on its triennial
 * verification. A scan raises only the tightest milestone already reached, and the unique key makes it idempotent.
 */
final class AlertScanner
{
    /** Milestones (days) of the rolling Swiss extract and of the SOA triennial verification. */
    private const array ExtractDays = [30, 14, 7, 0];

    public function __construct(private readonly VaultService $vault, private readonly VaultStatusResolver $status) {}

    /**
     * Raises the alerts due at $now for the current tenant; returns how many were created.
     */
    public function scan(CarbonImmutable $now): int
    {
        $this->vault->refreshStatuses($now);

        return $this->scanDeadlines($now) + $this->scanVault($now);
    }

    private function scanDeadlines(CarbonImmutable $now): int
    {
        $raised = 0;
        $offsets = (array) config('tender_management.alerts.deadline_offsets_hours', [168, 72, 24, 4]);
        rsort($offsets);

        $notices = TenderNotice::query()
            ->where('status', NoticeStatus::Open->value)
            ->whereHas('participations', fn ($query) => $query->whereIn('status', [ParticipationStatus::Scouting->value, ParticipationStatus::GoApproved->value, ParticipationStatus::PreparingEnvelopes->value]))
            ->get();

        foreach ($notices as $notice) {
            foreach (['submission_deadline' => $notice->submission_peremptory_deadline_at, 'clarification_deadline' => $notice->clarification_deadline_at] as $type => $deadline) {
                if ($deadline === null) {
                    continue;
                }

                $due = CarbonImmutable::parse($deadline);
                $hoursLeft = $now->diffInHours($due, false);

                if ($hoursLeft < 0) {
                    continue;
                }

                $reached = null;
                foreach ($offsets as $offset) {
                    if ($hoursLeft <= $offset) {
                        $reached = (int) $offset;
                    }
                }

                if ($reached === null) {
                    continue;
                }

                $severity = $reached <= 4 ? AlertSeverity::Critical : ($reached <= 72 ? AlertSeverity::Warning : AlertSeverity::Info);
                $raised += $this->raise((string) $notice->tenant_id, 'notice', (string) $notice->id, $type, '-'.$reached.'h', $severity, ['title' => $notice->title, 'hours' => $reached, 'due_at' => $due->toIso8601String()], $due, $now);
            }
        }

        return $raised;
    }

    private function scanVault(CarbonImmutable $now): int
    {
        $raised = 0;
        $offsets = (array) config('tender_management.alerts.expiry_offsets_days', [90, 60, 30, 7, 0]);
        $soaOffsets = (array) config('tender_management.alerts.soa_milestone_offsets_days', [90, 30, 0]);

        foreach ($this->vault->activeDocuments() as $document) {
            $expiry = $this->status->effectiveExpiry($document);

            if ($expiry !== null) {
                $days = (int) $now->startOfDay()->diffInDays($expiry->startOfDay(), false);
                $isExtract = $document->doc_type === VaultDocType::ChBetreibungsauszug;
                $milestone = $this->tightest($days, $isExtract ? self::ExtractDays : $offsets);

                if ($milestone !== null) {
                    $severity = $milestone <= 7 ? AlertSeverity::Critical : ($milestone <= 30 ? AlertSeverity::Warning : AlertSeverity::Info);
                    $raised += $this->raise((string) $document->tenant_id, 'vault_document', (string) $document->id, $isExtract ? 'betreibungsauszug_age' : 'vault_expiry', $milestone.'d', $severity, ['type' => $document->doc_type->value, 'days' => max(0, $days), 'due_at' => $expiry->toIso8601String()], $expiry, $now);
                }
            }

            if ($document->doc_type === VaultDocType::ItSoa && $document->intermediate_triennial_check_date !== null) {
                $check = CarbonImmutable::parse($document->intermediate_triennial_check_date->toDateString());
                $days = (int) $now->startOfDay()->diffInDays($check->startOfDay(), false);
                $milestone = $this->tightest($days, $soaOffsets);

                if ($milestone !== null) {
                    $raised += $this->raise((string) $document->tenant_id, 'vault_document', (string) $document->id, 'soa_milestone', $milestone.'d', $milestone <= 30 ? AlertSeverity::Warning : AlertSeverity::Info, ['type' => $document->doc_type->value, 'days' => max(0, $days), 'due_at' => $check->toIso8601String()], $check, $now);
                }
            }
        }

        return $raised;
    }

    /**
     * The smallest offset the remaining days already reached; null while the first offset is still ahead.
     *
     * @param  list<int>  $offsets
     */
    private function tightest(int $days, array $offsets): ?int
    {
        $reached = null;
        $sorted = $offsets;
        rsort($sorted);

        foreach ($sorted as $offset) {
            if ($days <= $offset) {
                $reached = (int) $offset;
            }
        }

        return $reached;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function raise(string $tenantId, string $subjectType, string $subjectId, string $type, string $milestone, AlertSeverity $severity, array $params, CarbonImmutable $due, CarbonImmutable $now): int
    {
        // Look first: a failed insert would abort the surrounding transaction on PostgreSQL.
        if (TenderAlert::query()->where('subject_id', $subjectId)->where('alert_type', $type)->where('milestone', $milestone)->exists()) {
            return 0;
        }

        try {
            $alert = TenderAlert::create([
                'tenant_id' => $tenantId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'alert_type' => $type,
                'milestone' => $milestone,
                'severity' => $severity->value,
                'message_key' => 'tenderManagement.alert.'.$type,
                'message_params' => $params,
                'due_at' => $due,
                'raised_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return 0;
        }

        event(new TenderAlertRaised((string) $alert->id, $tenantId, $type, $milestone, $severity->value, $alert->message_key, $params));

        return 1;
    }
}
