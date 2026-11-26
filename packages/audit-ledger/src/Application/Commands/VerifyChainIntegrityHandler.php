<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

use Enterprise\AuditLedger\Application\Exceptions\VerificationAlreadyRunning;
use Enterprise\AuditLedger\Contracts\AuditAlertGateway;
use Enterprise\AuditLedger\Domain\Enums\AuditCategory;
use Enterprise\AuditLedger\Domain\Events\AuditChainBroken;
use Enterprise\AuditLedger\Domain\Services\IntegrityVerifier;
use Enterprise\AuditLedger\Domain\ValueObjects\AuditAction;
use Enterprise\AuditLedger\Jobs\VerifyAuditChainsJob;
use Enterprise\AuditLedger\Models\IntegrityVerificationRun;
use Enterprise\AuditLedger\Support\ChainReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class VerifyChainIntegrityHandler
{
    public function __construct(
        private readonly IntegrityVerifier $verifier,
        private readonly ChainReader $reader,
        private readonly AppendAuditEntryHandler $append,
        private readonly AuditAlertGateway $alerts,
    ) {}

    /**
     * Records a queued run and dispatches its verification.
     *
     * @throws VerificationAlreadyRunning when a run of the tenant is already queued or running
     */
    public function queue(VerifyChainIntegrityCommand $command): IntegrityVerificationRun
    {
        $run = $this->createRun($command);

        VerifyAuditChainsJob::dispatch($run->id);

        return $run;
    }

    /**
     * @throws VerificationAlreadyRunning
     */
    public function createRun(VerifyChainIntegrityCommand $command): IntegrityVerificationRun
    {
        $stale = Carbon::now('UTC')->subMinutes((int) config('audit_ledger.verification.stale_run_minutes', 120));

        $active = IntegrityVerificationRun::query()
            ->where('tenant_id', $command->tenantId)
            ->whereIn('status', [IntegrityVerificationRun::STATUS_QUEUED, IntegrityVerificationRun::STATUS_RUNNING])
            ->where('created_at', '>', $stale)
            ->first();

        if ($active instanceof IntegrityVerificationRun) {
            throw new VerificationAlreadyRunning((string) $active->id);
        }

        return IntegrityVerificationRun::query()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $command->tenantId,
            'trigger' => $command->trigger,
            'status' => IntegrityVerificationRun::STATUS_QUEUED,
            'requested_by' => $command->requestedBy,
        ]);
    }

    /**
     * Runs the verification of a queued run and stores the outcome: valid, broken_at(sequence) or missing_range.
     */
    public function execute(IntegrityVerificationRun $run): IntegrityVerificationRun
    {
        $tenantId = (string) $run->tenant_id;
        $run->forceFill(['status' => IntegrityVerificationRun::STATUS_RUNNING, 'started_at' => Carbon::now('UTC')])->save();

        try {
            $result = $this->verifier->verify($this->reader->entries($tenantId), $this->reader->anchors($tenantId));
        } catch (Throwable $e) {
            report($e);
            $run->forceFill(['status' => IntegrityVerificationRun::STATUS_FAILED, 'error' => substr($e->getMessage(), 0, 1000), 'finished_at' => Carbon::now('UTC')])->save();

            return $run;
        }

        $run->forceFill([
            'status' => IntegrityVerificationRun::STATUS_COMPLETED,
            'outcome' => $result->outcome->value,
            'broken_at_sequence' => $result->brokenAtSequence,
            'missing_from_sequence' => $result->missingFromSequence,
            'missing_to_sequence' => $result->missingToSequence,
            'verified_entries' => $result->verifiedEntries,
            'last_sequence' => $result->lastSequence,
            'finished_at' => Carbon::now('UTC'),
        ])->save();

        $this->record($run);

        if (! $result->isValid()) {
            $this->raiseIncident($run);
        }

        return $run;
    }

    /**
     * The verification and its outcome are a platform event of the ledger itself.
     */
    private function record(IntegrityVerificationRun $run): void
    {
        try {
            $this->append->handle(new AppendAuditEntryCommand(
                tenantId: (string) $run->tenant_id,
                companyId: (string) $run->tenant_id,
                moduleCode: 'AUDIT',
                action: AuditAction::make('AUDIT_INTEGRITY_VERIFIED'),
                resourceType: 'AuditChain',
                resourceId: (string) $run->id,
                before: null,
                after: [
                    'run_id' => $run->id,
                    'trigger' => $run->trigger,
                    'outcome' => $run->outcome,
                    'verified_entries' => $run->verified_entries,
                    'broken_at_sequence' => $run->broken_at_sequence,
                    'missing_from_sequence' => $run->missing_from_sequence,
                    'missing_to_sequence' => $run->missing_to_sequence,
                ],
                actorUserId: $run->requested_by,
                category: AuditCategory::Platform,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function raiseIncident(IntegrityVerificationRun $run): void
    {
        $details = [
            'run_id' => (string) $run->id,
            'outcome' => (string) $run->outcome,
            'broken_at_sequence' => $run->broken_at_sequence,
            'missing_from_sequence' => $run->missing_from_sequence,
            'missing_to_sequence' => $run->missing_to_sequence,
        ];

        AuditChainBroken::dispatch((string) $run->tenant_id, (string) $run->id, (string) $run->outcome, $run->broken_at_sequence, $run->missing_from_sequence, $run->missing_to_sequence);

        try {
            $this->alerts->chainBroken((string) $run->tenant_id, $details);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
