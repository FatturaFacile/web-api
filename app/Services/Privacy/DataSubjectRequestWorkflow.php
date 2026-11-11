<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Privacy\PrivacyDataSubjectRequest;
use App\Models\Privacy\PrivacyDsrEvent;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CRM\Models\Consent;
use Modules\CRM\Services\ConsentService;
use RuntimeException;

/**
 * Workflow of the data subject requests: received, identity verified, in progress, completed or rejected, with the
 * statutory deadline tracked per request. Every step is stored in the history of the request and written to the
 * audit ledger (ids and statuses only).
 *
 * Deliberately conservative choices, to be confirmed by the data protection officer:
 * - work does not start, and no data is exported or erased, before the identity of the requester is verified;
 * - access and portability requests can be completed only after the export was produced;
 * - rectification requests and objections that are not about marketing are completed by hand, with a written
 *   response, because the system cannot decide what is correct or what a legitimate interest is.
 */
final readonly class DataSubjectRequestWorkflow
{
    public function __construct(
        private GlobalPrivacyComplianceService $privacy,
        private SubjectDataExporter $exporter,
        private PrivacyAudit $audit,
        private ConsentService $consents,
    ) {}

    public function find(string $tenantId, string $id): PrivacyDataSubjectRequest
    {
        return PrivacyDataSubjectRequest::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * Verifies the identity of the requester: the request moves from received to identity_verified.
     */
    public function verifyIdentity(PrivacyDataSubjectRequest $dsr, string $method, ?string $actorId, ?string $note = null): PrivacyDataSubjectRequest
    {
        return $this->transition($dsr, ['received'], 'identity_verified', 'identity_verified', $actorId, $note, [
            'identity_verified_at' => CarbonImmutable::now(),
            'identity_verified_by' => $this->uuidOrNull($actorId),
            'identity_verification_method' => $method,
            'handled_by' => $this->uuidOrNull($actorId) ?? $dsr->handled_by,
        ]);
    }

    /**
     * Starts the work on a request whose identity was verified.
     */
    public function start(PrivacyDataSubjectRequest $dsr, ?string $actorId, ?string $note = null): PrivacyDataSubjectRequest
    {
        return $this->transition($dsr, ['identity_verified'], 'started', 'in_progress', $actorId, $note, [
            'in_progress_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Rejects a request that is not closed yet, with the reason that is communicated to the data subject (art. 12(4) GDPR).
     */
    public function reject(PrivacyDataSubjectRequest $dsr, string $reason, ?string $actorId): PrivacyDataSubjectRequest
    {
        return $this->transition($dsr, ['received', 'identity_verified', 'in_progress'], 'rejected', 'rejected', $actorId, $reason, [
            'rejected_at' => CarbonImmutable::now(),
            'rejection_reason' => $reason,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Extends the deadline (art. 12(3) GDPR: two further months for complex or numerous requests, to be confirmed). One
     * extension only, with a reason.
     */
    public function extend(PrivacyDataSubjectRequest $dsr, int $days, string $reason, ?string $actorId): PrivacyDataSubjectRequest
    {
        if ($days < 1 || $days > PrivacySettings::MaxExtensionDays) {
            throw new RuntimeException('The extension must be between 1 and '.PrivacySettings::MaxExtensionDays.' days.');
        }

        return DB::transaction(function () use ($dsr, $days, $reason, $actorId): PrivacyDataSubjectRequest {
            $locked = $this->lock($dsr);
            if ($locked->isClosed()) {
                throw new RuntimeException('A closed request cannot be extended.');
            }

            if ($locked->extended_until !== null) {
                throw new RuntimeException('The deadline of this request was already extended once.');
            }

            $locked->update([
                'extended_until' => $locked->deadline_at->addDays($days),
                'extension_reason' => $reason,
            ]);
            PrivacyDsrEvent::record($locked, 'extended', $locked->status, $locked->status, $actorId, $reason);
            $this->audit->recordRequestEvent($locked, 'PRIVACY_DSR_EXTENDED', ['days' => $days], $actorId);

            return $locked->refresh();
        });
    }

    /**
     * Builds the zip export for an access or portability request and records the delivery. The request must be in
     * progress, so the identity was verified.
     *
     * @return string path of the temporary zip file
     */
    public function export(PrivacyDataSubjectRequest $dsr, ?string $actorId, ?string $ip): string
    {
        if (! in_array($dsr->request_type, ['access', 'portability'], true)) {
            throw new RuntimeException('Only access and portability requests produce an export.');
        }

        if ($dsr->status !== 'in_progress') {
            throw new RuntimeException('The export is available while the request is in progress, after the identity check.');
        }

        $tenantId = (string) $dsr->tenant_id;
        $path = $this->exporter->zip($tenantId, (string) $dsr->subject_type, (string) $dsr->subject_id);

        $this->privacy->logSensitiveAccess($tenantId, $actorId, (string) $dsr->subject_type, (string) $dsr->subject_id, 'full_subject_export', 'portability_export', $ip);
        PrivacyDsrEvent::record($dsr, 'exported', $dsr->status, $dsr->status, $actorId, 'Export zip (JSON and CSV) produced.');
        $this->audit->recordRequestEvent($dsr, 'PRIVACY_DSR_EXPORTED', ['subject_type' => $dsr->subject_type, 'subject_id' => $dsr->subject_id], $actorId);

        return $path;
    }

    /**
     * Completes a request in progress. The action taken depends on the type: erasure runs the erasure with the
     * retention check, restriction marks the data as restricted, an objection to marketing withdraws the consents,
     * access and portability need the export, rectification and other objections need the written response.
     */
    public function complete(PrivacyDataSubjectRequest $dsr, ?string $responseSummary, ?string $actorId): PrivacyDataSubjectRequest
    {
        if ($dsr->status !== 'in_progress') {
            throw new RuntimeException("Request is {$dsr->status}, not in_progress.");
        }

        $tenantId = (string) $dsr->tenant_id;

        switch ($dsr->request_type) {
            case 'erasure':
                $updated = $this->privacy->executeErasureOrRestriction($tenantId, $dsr, $actorId);
                $updated->update(['response_summary' => $responseSummary]);
                $this->closeAs($updated, $actorId);

                return $updated->refresh();

            case 'restriction':
                $this->privacy->restrictProcessing($tenantId, $dsr, $actorId);

                break;

            case 'access':
            case 'portability':
                if (! PrivacyDsrEvent::withoutGlobalScope(TenantScope::class)->where('request_id', $dsr->id)->where('event', 'exported')->exists()) {
                    throw new RuntimeException('Produce the export before completing an access or portability request.');
                }

                break;

            case 'objection':
                if (in_array($dsr->subject_type, ['contact', 'lead'], true) && $this->withdrawMarketingConsents($dsr, $actorId) === 0 && blank($responseSummary)) {
                    throw new RuntimeException('There was no marketing consent to withdraw: describe the assessment of the objection in the response.');
                }

                break;

            default:
                if (blank($responseSummary)) {
                    throw new RuntimeException('A written response is required to complete this request.');
                }
        }

        return $this->transition($dsr, ['in_progress'], 'completed', 'completed', $actorId, $responseSummary, [
            'completed_at' => CarbonImmutable::now(),
            'response_summary' => $responseSummary,
        ]);
    }

    /**
     * Withdraws every granted marketing consent of a contact or lead (art. 21(2) GDPR: objection to direct marketing).
     *
     * @return int number of consents withdrawn
     */
    private function withdrawMarketingConsents(PrivacyDataSubjectRequest $dsr, ?string $actorId): int
    {
        $isContact = $dsr->subject_type === 'contact';
        $contactId = $isContact ? (string) $dsr->subject_id : null;
        $leadId = $isContact ? null : (string) $dsr->subject_id;
        $withdrawn = 0;

        foreach ($this->consents->summary($contactId, $leadId) as $state) {
            if ($state['status'] !== Consent::Granted) {
                continue;
            }

            $this->consents->record((string) $dsr->tenant_id, [
                'contact_id' => $contactId,
                'lead_id' => $leadId,
                'channel' => $state['channel'],
                'purpose' => $state['purpose'],
                'status' => Consent::Withdrawn,
                'source' => 'api',
                'evidence' => "Objection of the data subject, request {$dsr->id}",
            ], $actorId);
            $withdrawn++;
        }

        return $withdrawn;
    }

    /**
     * Closes a request that the erasure already stamped with its own final status: history and ledger only.
     */
    private function closeAs(PrivacyDataSubjectRequest $dsr, ?string $actorId): void
    {
        PrivacyDsrEvent::record($dsr, 'completed', 'in_progress', $dsr->status, $actorId, $dsr->response_summary);
        $this->audit->recordRequestEvent($dsr, 'PRIVACY_DSR_COMPLETED', ['status' => $dsr->status], $actorId);
    }

    /**
     * @param  list<string>  $from  statuses the request may be in
     * @param  array<string, mixed>  $changes
     */
    private function transition(PrivacyDataSubjectRequest $dsr, array $from, string $event, string $to, ?string $actorId, ?string $note, array $changes): PrivacyDataSubjectRequest
    {
        return DB::transaction(function () use ($dsr, $from, $event, $to, $actorId, $note, $changes): PrivacyDataSubjectRequest {
            $locked = $this->lock($dsr);
            if (! in_array($locked->status, $from, true)) {
                throw new RuntimeException("Request is {$locked->status}: expected ".implode(' or ', $from).'.');
            }

            $previous = $locked->status;
            $locked->update($changes + ['status' => $to]);
            PrivacyDsrEvent::record($locked, $event, $previous, $to, $actorId, $note);
            $this->audit->recordRequestEvent($locked, 'PRIVACY_DSR_'.strtoupper($event), ['from' => $previous, 'to' => $to], $actorId);

            return $locked->refresh();
        });
    }

    private function lock(PrivacyDataSubjectRequest $dsr): PrivacyDataSubjectRequest
    {
        return PrivacyDataSubjectRequest::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $dsr->tenant_id)
            ->whereKey($dsr->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function uuidOrNull(?string $id): ?string
    {
        return $id !== null && Str::isUuid($id) ? $id : null;
    }
}
