<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance;

use App\Domain\Privacy\Erasure\SelectiveAnonymizationService;
use App\Enums\Privacy\RetentionExpiryAction;
use App\Models\Privacy\DataRetentionPolicyRule;
use App\Models\Privacy\PrivacyRetentionRunLog;
use App\Services\Privacy\PersonalDataEntry;
use App\Services\Privacy\PersonalDataRegistry;
use App\Services\Privacy\PrivacyAudit;
use Carbon\CarbonImmutable;
use Closure;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applies the retention rules of a tenant. Operational personal data (rejected candidates, inactive leads, closed
 * tickets) past the rule window is cryptographically anonymised; statutory locked modules (invoices, payroll) are only
 * counted and never touched. A hard delete rule degrades to anonymisation: rows keep their keys because other records
 * and the audit trail reference them.
 */
final readonly class RetentionPurgeService
{
    public function __construct(
        private PersonalDataRegistry $registry,
        private SelectiveAnonymizationService $anonymizer,
        private PrivacyAudit $audit,
    ) {}

    /**
     * Runs the enabled rules of one tenant and stores the immutable run log.
     */
    public function runForTenant(string $tenantId, bool $dryRun = false): PrivacyRetentionRunLog
    {
        $startedAt = CarbonImmutable::now();
        $evaluated = $purged = $anonymized = $skipped = 0;
        $details = [];

        $rules = DataRetentionPolicyRule::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('is_enabled', true)->orderBy('module_code')->get();
        foreach ($rules as $rule) {
            $detail = ['module_code' => $rule->module_code, 'evaluated' => 0, 'anonymized' => 0, 'statutory_skipped' => 0];

            if ($rule->is_statutory_locked || $rule->action_on_expiry === RetentionExpiryAction::ArchiveLock) {
                $rows = $this->lockedRows($tenantId, $rule->entity_class);
                $detail['evaluated'] = $detail['statutory_skipped'] = $rows;
                $evaluated += $rows;
                $skipped += $rows;
            } else {
                $target = $this->target($tenantId, $rule, $startedAt);
                if ($target !== null) {
                    $result = $this->anonymizer->anonymizeScoped($target['entry'], $target['scope'], $dryRun);
                    $detail['evaluated'] = $result['evaluated'];
                    $detail['anonymized'] = $result['anonymized'];
                    $evaluated += $result['evaluated'];
                    if ($rule->action_on_expiry === RetentionExpiryAction::HardDelete) {
                        $purged += $result['anonymized'];
                    } else {
                        $anonymized += $result['anonymized'];
                    }
                }
            }

            $details[] = $detail;
        }

        $log = PrivacyRetentionRunLog::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenantId,
            'dry_run' => $dryRun,
            'evaluated_count' => $evaluated,
            'purged_count' => $purged,
            'anonymized_count' => $anonymized,
            'statutory_skipped_count' => $skipped,
            'details' => $details,
            'started_at' => $startedAt,
            'finished_at' => CarbonImmutable::now(),
        ]);

        $this->audit->record($tenantId, 'PRIVACY_RETENTION_RUN', PrivacyRetentionRunLog::class, (string) $log->id, [
            'dry_run' => $dryRun, 'evaluated' => $evaluated, 'purged' => $purged, 'anonymized' => $anonymized, 'statutory_skipped' => $skipped,
        ]);

        return $log;
    }

    /**
     * Tenants with at least one enabled rule.
     *
     * @return list<string>
     */
    public function tenantsWithRules(): array
    {
        return DataRetentionPolicyRule::withoutGlobalScope(TenantScope::class)->where('is_enabled', true)->distinct()->pluck('tenant_id')->map(static fn (mixed $id): string => (string) $id)->all();
    }

    private function lockedRows(string $tenantId, string $table): int
    {
        if (! preg_match('/^[a-z0-9_]+$/', $table) || ! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        if (Schema::hasColumn($table, 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        return (int) $query->count();
    }

    /**
     * @return array{entry: PersonalDataEntry, scope: Closure}|null
     */
    private function target(string $tenantId, DataRetentionPolicyRule $rule, CarbonImmutable $now): ?array
    {
        $cutoff = $now->subDays($rule->retention_days)->toDateTimeString();

        return match ($rule->module_code) {
            'MOD_070_CANDIDATES' => $this->entry('hr_job_applications', static fn (Builder $q) => $q->where('tenant_id', $tenantId)
                ->where('status', 'rejected')->whereNull('employee_id')
                ->whereRaw('COALESCE(decided_at, updated_at) <= ?', [$cutoff])),
            'MOD_034_LEADS' => $this->entry('crm_leads', static fn (Builder $q) => $q->where('tenant_id', $tenantId)
                ->whereNull('converted_contact_id')->where('updated_at', '<=', $cutoff)),
            'MOD_050_HELPDESK' => $this->helpdesk(static fn (Builder $q) => $q->where('tenant_id', $tenantId)
                ->whereNotNull('resolved_at')->where('resolved_at', '<=', $cutoff)),
            default => null,
        };
    }

    /**
     * @param  Closure(Builder): mixed  $scope
     * @return array{entry: PersonalDataEntry, scope: Closure}|null
     */
    private function entry(string $table, Closure $scope): ?array
    {
        $entry = $this->registry->find($table);

        return $entry !== null && Schema::hasTable($table) ? ['entry' => $entry, 'scope' => $scope] : null;
    }

    /**
     * @param  Closure(Builder): mixed  $scope
     * @return array{entry: PersonalDataEntry, scope: Closure}|null
     */
    private function helpdesk(Closure $scope): ?array
    {
        return Schema::hasTable('esm_tickets') ? ['entry' => $this->anonymizer->helpdeskTicketEntry(), 'scope' => $scope] : null;
    }
}
