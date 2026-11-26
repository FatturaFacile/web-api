<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Support;

use Enterprise\AuditLedger\Domain\Enums\AuditCategory;
use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Support\Carbon;

/**
 * Retention of the audit entries by category: accounting entries ten years (CH CO art. 957a/958f, GeBüV, LIVA art. 70; IT
 * art. 2220 c.c., DPR 600/73, DPR 633/72), access logs of administrators at least six months (nLPD/OPDa, Garante
 * 27/11/2008, GDPR). The configured period can lengthen a legal minimum but never go below it, and an entry may only
 * expire once an anchored checkpoint covers it.
 */
final class AuditRetentionPolicy
{
    /** Modules whose entries are accounting records. */
    private const array ACCOUNTING_MODULES = ['GENERALLEDGER', 'ACCOUNTING', 'INVOICING', 'SDI', 'TREASURY', 'VAT', 'ITALIANVAT', 'SWISSVAT'];

    /**
     * The retention key of an entry: `accounting` for accounting records, else its category.
     */
    public function keyFor(string $category, string $moduleCode): string
    {
        return in_array(strtoupper($moduleCode), self::ACCOUNTING_MODULES, true) ? 'accounting' : $category;
    }

    public function minimumYears(string $key): float
    {
        return (float) config('audit_ledger.retention.minimum_years.'.$key, 10);
    }

    /**
     * Years an entry of the key is kept: the configured period, never below the legal minimum.
     */
    public function years(string $key): float
    {
        $configured = config('audit_ledger.retention.years.'.$key);

        return max($this->minimumYears($key), is_numeric($configured) ? (float) $configured : 0.0);
    }

    public function expiresAt(Carbon $occurredAt, string $key): Carbon
    {
        return $occurredAt->copy()->addDays((int) ceil($this->years($key) * 365.25));
    }

    /**
     * Whether an entry may leave the active ledger: its period is over and an anchored checkpoint of the tenant covers its
     * sequence (the proof of the chain up to there is on the write-once target).
     */
    public function isExpirable(AuditLedgerEntry $entry, ?Carbon $now = null): bool
    {
        $category = $entry->category ?? AuditCategory::infer((string) $entry->action, (string) $entry->module_code)->value;
        $key = $this->keyFor($category, (string) $entry->module_code);

        if ($this->expiresAt($entry->event_timestamp, $key)->greaterThan($now ?? Carbon::now('UTC'))) {
            return false;
        }

        return AuditCheckpoint::query()
            ->where('tenant_id', $entry->tenant_id)
            ->whereNotNull('anchored_at')
            ->where('sequence', '>=', (int) $entry->sequence)
            ->exists();
    }
}
