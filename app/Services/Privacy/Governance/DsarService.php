<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance;

use App\Domain\Privacy\Erasure\ErasureStrategyResolver;
use App\Domain\Privacy\Erasure\SelectiveAnonymizationService;
use App\Domain\Privacy\Erasure\StatutoryRetentionGuard;
use App\Enums\Privacy\DsarRequestType;
use App\Enums\Privacy\DsarStatus;
use App\Models\Privacy\PrivacySubjectRequestDsar;
use App\Models\Tenant;
use App\Services\Privacy\PrivacyAudit;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DSAR workflow of the Privacy Governance suite: numbering, statutory deadline, identity check and the fiscal-safe
 * selective erasure (GDPR art. 12/17, nLPD art. 25/32).
 */
final readonly class DsarService
{
    public function __construct(
        private StatutoryRetentionGuard $guard,
        private SelectiveAnonymizationService $anonymizer,
        private ErasureStrategyResolver $strategies,
        private PrivacyAudit $audit,
    ) {}

    /**
     * @param  array{subject_type: string, subject_entity_id?: string|null, subject_email: string, subject_full_name: string, request_type: string, received_at?: string|null, audit_notes?: string|null}  $data
     */
    public function create(string $tenantId, array $data): PrivacySubjectRequestDsar
    {
        $receivedAt = CarbonImmutable::parse($data['received_at'] ?? now());

        $dsar = DB::transaction(function () use ($tenantId, $data, $receivedAt): PrivacySubjectRequestDsar {
            $year = $receivedAt->year;
            $count = PrivacySubjectRequestDsar::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)->where('request_number', 'like', "DSAR-{$year}-%")->lockForUpdate()->count();

            return PrivacySubjectRequestDsar::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $tenantId,
                'request_number' => sprintf('DSAR-%d-%04d', $year, $count + 1),
                'subject_type' => $data['subject_type'],
                'subject_entity_id' => $data['subject_entity_id'] ?? null,
                'subject_email' => $data['subject_email'],
                'subject_full_name' => $data['subject_full_name'],
                'request_type' => $data['request_type'],
                'received_at' => $receivedAt,
                'statutory_deadline_at' => $receivedAt->addDays(PrivacySubjectRequestDsar::DeadlineDays),
                'status' => DsarStatus::Open,
                'audit_notes' => $data['audit_notes'] ?? null,
            ]);
        });

        $this->audit->record($tenantId, 'PRIVACY_DSAR_CREATED', PrivacySubjectRequestDsar::class, (string) $dsar->id, ['request_number' => $dsar->request_number, 'request_type' => $dsar->request_type->value]);

        return $dsar;
    }

    public function find(string $tenantId, string $id): PrivacySubjectRequestDsar
    {
        return PrivacySubjectRequestDsar::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->whereKey($id)->firstOrFail();
    }

    public function verifyIdentity(PrivacySubjectRequestDsar $dsar, string $method): PrivacySubjectRequestDsar
    {
        if ($dsar->status !== DsarStatus::Open) {
            throw new RuntimeException('Only an open request can have its identity verified.');
        }

        $dsar->update(['status' => DsarStatus::IdentityVerified, 'identity_verified_at' => now(), 'identity_verification_method' => $method]);
        $this->audit->record((string) $dsar->tenant_id, 'PRIVACY_DSAR_IDENTITY_VERIFIED', PrivacySubjectRequestDsar::class, (string) $dsar->id, ['method' => $method]);

        return $dsar->refresh();
    }

    /**
     * Extends the deadline by up to 60 days with a written justification (art. 12(3) GDPR). One extension only.
     */
    public function extend(PrivacySubjectRequestDsar $dsar, int $days, string $justification): PrivacySubjectRequestDsar
    {
        if ($dsar->isClosed() || $dsar->deadline_extended) {
            throw new RuntimeException('A closed or already extended request cannot be extended.');
        }

        $days = min($days, PrivacySubjectRequestDsar::MaxExtensionDays);
        $dsar->update(['deadline_extended' => true, 'extension_justification' => $justification, 'statutory_deadline_at' => $dsar->statutory_deadline_at->addDays($days)]);

        return $dsar->refresh();
    }

    /**
     * Erasure (right to be forgotten) that respects the fiscal retention lock.
     *
     * @return array{dry_run: bool, status: string, fiscal_lock: array<string, mixed>, to_anonymize: list<array{table: string, label: string, module: string, rows: int}>, protected: list<array<string, mixed>>, refusal_legal_justification: string|null}
     */
    public function executeErasure(PrivacySubjectRequestDsar $dsar, bool $dryRun): array
    {
        if ($dsar->request_type !== DsarRequestType::ErasureRightToBeForgotten) {
            throw new RuntimeException('Only an erasure request can be executed.');
        }

        if ($dsar->identity_verified_at === null) {
            throw new RuntimeException('The identity of the requester must be verified before any erasure.');
        }

        if ($dsar->isClosed()) {
            throw new RuntimeException('The request is already closed.');
        }

        $tenantId = (string) $dsar->tenant_id;
        $entityId = (string) $dsar->subject_entity_id;
        if ($entityId === '') {
            throw new RuntimeException('The request is not linked to a subject record.');
        }

        $report = $this->guard->inspect($tenantId, $dsar->subject_type, $entityId);
        $strategy = $this->strategies->forCountry(Tenant::query()->whereKey($tenantId)->value('country'));
        $justification = $report->isLocked() ? $strategy->refusalJustification($report) : null;
        $status = $report->isLocked() ? DsarStatus::PartiallyRefusedFiscalRetention : DsarStatus::Completed;

        if ($dryRun) {
            return [
                'dry_run' => true,
                'status' => $status->value,
                'fiscal_lock' => $report->toArray(),
                'to_anonymize' => $this->anonymizer->preview($tenantId, $dsar->subject_type, $entityId, $report->isLocked()),
                'protected' => $report->records,
                'refusal_legal_justification' => $justification,
            ];
        }

        $anonymized = DB::transaction(function () use ($dsar, $tenantId, $entityId, $report, $status, $justification): array {
            $done = $this->anonymizer->execute($tenantId, $dsar->subject_type, $entityId, $report->isLocked());
            $dsar->update(['status' => $status, 'completed_at' => now(), 'refusal_legal_justification' => $justification]);

            return $done;
        });

        $this->audit->record($tenantId, 'PRIVACY_DSAR_ERASURE_EXECUTED', PrivacySubjectRequestDsar::class, (string) $dsar->id, [
            'status' => $status->value,
            'tables' => array_column($anonymized, 'rows', 'table'),
            'protected_rows' => $report->protectedRowCount(),
        ]);

        return [
            'dry_run' => false,
            'status' => $status->value,
            'fiscal_lock' => $report->toArray(),
            'to_anonymize' => $anonymized,
            'protected' => $report->records,
            'refusal_legal_justification' => $justification,
        ];
    }
}
