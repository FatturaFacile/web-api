<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Privacy\PrivacyRetentionPolicy;
use App\Models\Privacy\PrivacyRetentionRun;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Automatic retention policies: rows older than the configured number of days are anonymised, never deleted. Only
 * tables the registry classifies as erasable and ages out by a timestamp can have a policy: accounting, payroll and
 * evidence records are refused, because their retention is fixed by law.
 *
 * The number of days is chosen by the company: this code ships no default period and does not state what the law
 * requires for operational data. A dry run counts the rows that would be anonymised without touching them. Every run
 * is stored in privacy_retention_runs and written to the audit ledger.
 */
final readonly class PrivacyRetentionService
{
    public function __construct(
        private PersonalDataRegistry $registry,
        private PersonalDataAnonymizer $anonymizer,
        private PrivacyAudit $audit,
    ) {}

    /**
     * Creates or updates the policy of a table.
     */
    public function savePolicy(string $tenantId, string $entryKey, int $retentionDays, bool $isActive = true, ?string $legalBasisNote = null): PrivacyRetentionPolicy
    {
        $entry = $this->registry->findOrFail($entryKey);
        if (! $entry->supportsRetentionPolicy()) {
            throw new InvalidArgumentException("The table [{$entryKey}] cannot have a retention policy: its retention is fixed by law or it cannot be aged out ({$entry->retention->value}).");
        }

        if ($retentionDays < 1) {
            throw new InvalidArgumentException('The retention period must be at least 1 day.');
        }

        $policy = PrivacyRetentionPolicy::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('entry_key', $entryKey)->first()
            ?? new PrivacyRetentionPolicy(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entry_key' => $entryKey]);
        $policy->fill(['retention_days' => $retentionDays, 'is_active' => $isActive, 'legal_basis_note' => $legalBasisNote, 'action' => 'anonymise'])->save();

        $this->audit->record($tenantId, 'PRIVACY_RETENTION_POLICY_SAVED', PrivacyRetentionPolicy::class, (string) $policy->id, [
            'entry_key' => $entryKey,
            'retention_days' => $retentionDays,
            'is_active' => $isActive,
        ]);

        return $policy;
    }

    /**
     * Runs every active policy of the company (or the one of a table).
     *
     * @return list<PrivacyRetentionRun>
     */
    public function runForTenant(string $tenantId, bool $dryRun, ?string $entryKey = null, ?string $actorId = null): array
    {
        $policies = PrivacyRetentionPolicy::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->when($entryKey !== null, static fn ($query) => $query->where('entry_key', $entryKey))
            ->orderBy('entry_key')
            ->get();

        $runs = [];
        foreach ($policies as $policy) {
            $runs[] = $this->runPolicy($policy, $dryRun, $actorId);
        }

        return $runs;
    }

    public function runPolicy(PrivacyRetentionPolicy $policy, bool $dryRun, ?string $actorId = null): PrivacyRetentionRun
    {
        $tenantId = (string) $policy->tenant_id;
        $started = CarbonImmutable::now();
        $entry = $this->registry->find((string) $policy->entry_key);

        if ($entry === null || ! $entry->supportsRetentionPolicy() || $entry->ageColumn === null) {
            return $this->store($policy, $dryRun, $started, 0, 0, 'skipped', ['reason' => 'The table is no longer eligible for a retention policy.'], $actorId);
        }

        $cutoff = $started->subDays((int) $policy->retention_days);
        $scope = function (Builder $query) use ($entry, $tenantId, $cutoff): void {
            if ($entry->hasTenantColumn) {
                $query->where('tenant_id', $tenantId);
            }

            $query->where((string) $entry->ageColumn, '<', $cutoff);
        };

        try {
            $pending = $this->anonymizer->pendingIds($entry, $scope);
            $matched = count($pending);
            $details = ['cutoff' => $cutoff->toIso8601String(), 'retention_days' => (int) $policy->retention_days];

            if ($dryRun) {
                $details['sample_ids'] = array_slice($pending, 0, 20);

                return $this->store($policy, true, $started, $matched, 0, 'completed', $details, $actorId);
            }

            $result = $this->anonymizer->anonymize($entry, $scope);
            $details['columns'] = $result['columns'];
            $details['skipped_columns'] = $result['skipped_columns'];
            $policy->update(['last_run_at' => CarbonImmutable::now()]);

            return $this->store($policy, false, $started, $matched, $result['rows'], 'completed', $details, $actorId);
        } catch (Throwable $exception) {
            report($exception);

            return $this->store($policy, $dryRun, $started, 0, 0, 'failed', ['error' => $exception::class], $actorId);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function store(PrivacyRetentionPolicy $policy, bool $dryRun, CarbonImmutable $started, int $matched, int $processed, string $status, array $details, ?string $actorId): PrivacyRetentionRun
    {
        $run = PrivacyRetentionRun::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $policy->tenant_id,
            'policy_id' => (string) $policy->id,
            'entry_key' => (string) $policy->entry_key,
            'dry_run' => $dryRun,
            'matched_count' => $matched,
            'processed_count' => $processed,
            'status' => $status,
            'started_at' => $started,
            'finished_at' => CarbonImmutable::now(),
            'details' => $details,
            'triggered_by' => $actorId !== null && Str::isUuid($actorId) ? $actorId : null,
        ]);

        $this->audit->record((string) $policy->tenant_id, $dryRun ? 'PRIVACY_RETENTION_DRY_RUN' : 'PRIVACY_RETENTION_APPLIED', PrivacyRetentionRun::class, (string) $run->id, [
            'entry_key' => (string) $policy->entry_key,
            'matched' => $matched,
            'processed' => $processed,
            'status' => $status,
        ], $actorId);

        return $run;
    }
}
