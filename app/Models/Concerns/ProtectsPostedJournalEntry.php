<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Database\Concerns\ProtectsRecords;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\FiduciaryPortal\Models\FiduciaryAuditLog;
use Modules\GeneralLedger\Models\FiscalPeriod;

/**
 * A posted journal entry is corrected by reversing it: from posted it may only step to reversed, and only the
 * reversal columns move.
 */
trait ProtectsPostedJournalEntry
{
    use ProtectsRecords;

    /**
     * @var list<string>
     */
    private const array REVERSAL_COLUMNS = ['status', 'reversal_date', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];

    /**
     * @var list<string>
     */
    private const array FIDUCIARY_VERIFICATION_COLUMNS = ['fiduciary_verified', 'fiduciary_verified_at', 'fiduciary_verified_by', 'fiduciary_verification_note', 'updated_at'];

    protected function recordProtectionViolation(string $operation): ?string
    {
        if ($operation === 'update' && ! $this->changesOutside(self::FIDUCIARY_VERIFICATION_COLUMNS)) {
            return null;
        }

        if ($this->isInFiduciaryLockedPeriod()) {
            $this->logBlockedLockedPeriodMutation($operation);

            return 'Fiscal period is locked by the external fiduciary: journal entry mutations are prohibited.';
        }

        $original = $this->getOriginal('status');

        if ($operation === 'create' || ! in_array($original, ['posted', 'reversed'], true)) {
            return null;
        }

        if ($operation === 'update' && $original === 'posted' && in_array($this->getAttribute('status'), ['posted', 'reversed'], true) && ! $this->changesOutside(self::REVERSAL_COLUMNS)) {
            return null;
        }

        return 'A posted journal entry is immutable: reverse it.';
    }

    private function isInFiduciaryLockedPeriod(): bool
    {
        $periodId = $this->getAttribute('fiscal_period_id');
        if (! is_string($periodId) || $periodId === '') {
            return false;
        }

        $period = FiscalPeriod::withoutGlobalScopes()->find($periodId);

        return $period !== null && $period->isFiduciaryLocked();
    }

    private function logBlockedLockedPeriodMutation(string $operation): void
    {
        if (! Schema::hasTable('fiduciary_audit_logs')) {
            return;
        }

        $tenantId = $this->getAttribute('tenant_id');
        $actor = auth()->user();

        FiduciaryAuditLog::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => is_string($tenantId) ? $tenantId : null,
            'company_id' => $this->getAttribute('company_id'),
            'actor_user_id' => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
            'actor_name' => $actor?->getAttribute('name'),
            'actor_role' => null,
            'action' => 'locked_period_mutation_blocked',
            'target_type' => 'journal_entry',
            'target_id' => $this->getKey() !== null ? (string) $this->getKey() : null,
            'target_reference' => (string) ($this->getAttribute('entry_number') ?? ''),
            'summary' => "Blocked [{$operation}] mutation on journal entry in fiduciary-locked period.",
            'metadata_json' => [
                'operation' => $operation,
                'fiscal_period_id' => $this->getAttribute('fiscal_period_id'),
                'posting_date' => $this->getAttribute('posting_date'),
            ],
            'ip_address' => request()?->ip(),
        ]);
    }
}
