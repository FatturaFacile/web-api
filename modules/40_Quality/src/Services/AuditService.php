<?php

declare(strict_types=1);

namespace Modules\Quality\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\QmsAudit;
use Modules\Quality\Models\QmsAuditChecklistItem;
use Modules\Quality\Models\QmsAuditFinding;

/**
 * Internal audits: planned with a checklist, performed (results recorded item by item, findings raised), completed.
 * A major or minor non-conformity finding raises a non-conformity of source audit, linked to the finding; the
 * checklist item of the finding is marked non-conforming.
 */
final readonly class AuditService
{
    public function __construct(
        private QualityNumbers $numbers,
        private QualityLedger $ledger,
        private NonconformityService $nonconformities,
    ) {}

    /**
     * @param  array<string, mixed>  $data  including checklist: list of {clause?, requirement}
     */
    public function create(string $tenantId, array $data): QmsAudit
    {
        return DB::transaction(function () use ($tenantId, $data): QmsAudit {
            $audit = QmsAudit::create([
                'tenant_id' => $tenantId,
                'number' => $this->numbers->next('qms_audits', 'AUD', $tenantId),
                'title' => $data['title'],
                'scope' => $data['scope'] ?? null,
                'audit_type' => $data['audit_type'] ?? 'internal',
                'planned_date' => $data['planned_date'],
                'lead_auditor_id' => $data['lead_auditor_id'] ?? null,
                'auditee' => $data['auditee'] ?? null,
                'status' => 'planned',
            ]);

            foreach (array_values($data['checklist'] ?? []) as $position => $item) {
                QmsAuditChecklistItem::create([
                    'tenant_id' => $tenantId,
                    'audit_id' => $audit->id,
                    'position' => $position + 1,
                    'clause' => $item['clause'] ?? null,
                    'requirement' => $item['requirement'],
                    'result' => 'pending',
                ]);
            }

            $this->ledger->record('QMS_AUDIT_PLANNED', $audit, ['planned_date' => $audit->planned_date->toDateString(), 'checklist_items' => count($data['checklist'] ?? [])]);

            return $audit;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QmsAudit $audit, array $data): QmsAudit
    {
        if ($audit->status !== 'planned') {
            throw ValidationException::withMessages(['status' => ["The audit is {$audit->status}: only a planned audit can be changed."]]);
        }

        $audit->update(array_intersect_key($data, array_flip(['title', 'scope', 'audit_type', 'planned_date', 'lead_auditor_id', 'auditee'])));

        return $audit->refresh();
    }

    public function start(QmsAudit $audit): QmsAudit
    {
        return $this->transition($audit, ['planned'], 'in_progress', 'QMS_AUDIT_STARTED', ['performed_date' => now()->toDateString()]);
    }

    public function complete(QmsAudit $audit, ?string $summary): QmsAudit
    {
        $pending = QmsAuditChecklistItem::query()->where('audit_id', $audit->id)->where('result', 'pending')->count();
        if ($pending > 0) {
            throw ValidationException::withMessages(['status' => ["{$pending} checklist item(s) still have no result."]]);
        }

        return $this->transition($audit, ['in_progress'], 'completed', 'QMS_AUDIT_COMPLETED', ['summary' => $summary]);
    }

    public function cancel(QmsAudit $audit): QmsAudit
    {
        return $this->transition($audit, ['planned', 'in_progress'], 'cancelled', 'QMS_AUDIT_CANCELLED', []);
    }

    public function recordResult(QmsAudit $audit, QmsAuditChecklistItem $item, string $result, ?string $notes): QmsAuditChecklistItem
    {
        if ($audit->status !== 'in_progress' || (string) $item->audit_id !== (string) $audit->id) {
            throw ValidationException::withMessages(['status' => ['Results can be recorded while the audit is in progress.']]);
        }

        $item->update(['result' => $result, 'notes' => $notes ?? $item->notes]);

        return $item->refresh();
    }

    /**
     * @param  array{classification: string, description: string, checklist_item_id?: string|null, severity?: string|null, owner_id?: string|null}  $data
     */
    public function addFinding(QmsAudit $audit, array $data, ?string $actorId): QmsAuditFinding
    {
        if ($audit->status !== 'in_progress') {
            throw ValidationException::withMessages(['status' => ['Findings are raised while the audit is in progress.']]);
        }

        return DB::transaction(function () use ($audit, $data, $actorId): QmsAuditFinding {
            $item = null;
            if (! empty($data['checklist_item_id'])) {
                $item = QmsAuditChecklistItem::query()->where('audit_id', $audit->id)->findOrFail($data['checklist_item_id']);
            }

            $finding = QmsAuditFinding::create([
                'tenant_id' => $audit->tenant_id,
                'audit_id' => $audit->id,
                'checklist_item_id' => $item?->id,
                'number' => $this->numbers->next('qms_audit_findings', 'FND', (string) $audit->tenant_id),
                'classification' => $data['classification'],
                'description' => $data['description'],
                'status' => 'open',
            ]);

            $isNonconformity = in_array($finding->classification, ['major_nc', 'minor_nc'], true);
            if ($isNonconformity) {
                $nonconformity = $this->nonconformities->create((string) $audit->tenant_id, [
                    'title' => "Audit {$audit->number}: ".mb_substr($finding->description, 0, 150),
                    'description' => $finding->description,
                    'source' => 'audit',
                    'severity' => $finding->classification === 'major_nc' ? 'major' : 'minor',
                    'audit_finding_id' => $finding->id,
                    'owner_id' => $data['owner_id'] ?? null,
                ], $actorId);
                $finding->update(['nonconformity_id' => $nonconformity->id]);
                $item?->update(['result' => 'nonconforming']);
            } elseif ($finding->classification === 'observation') {
                $item?->update(['result' => 'observation']);
            }

            $this->ledger->record('QMS_AUDIT_FINDING_RAISED', $finding, ['classification' => $finding->classification, 'nonconformity_id' => $finding->nonconformity_id]);

            return $finding->refresh();
        });
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(QmsAudit $audit, array $from, string $to, string $event, array $changes): QmsAudit
    {
        return DB::transaction(function () use ($audit, $from, $to, $event, $changes): QmsAudit {
            $locked = QmsAudit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => ["The audit is {$locked->status}: expected ".implode(' or ', $from).'.']]);
            }

            $locked->update($changes + ['status' => $to]);
            $this->ledger->record($event, $locked, ['to' => $to]);

            return $locked->refresh();
        });
    }
}
