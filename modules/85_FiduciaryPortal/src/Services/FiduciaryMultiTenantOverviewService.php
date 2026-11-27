<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services;

use App\Models\Tenant;
use App\Models\User;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\FiduciaryPortal\Models\FiduciaryAccountTranscoding;
use Modules\FiduciaryPortal\Models\FiduciaryEntryNote;
use Modules\FiduciaryPortal\Models\FiduciaryExportRun;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;

final readonly class FiduciaryMultiTenantOverviewService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(User $user, string $activeTenantId): array
    {
        $tenants = $user->tenants()->orderBy('name')->get();
        $clients = [];

        foreach ($tenants as $tenant) {
            $clients[] = $this->summarizeTenant($tenant);
        }

        $activeSummary = collect($clients)->firstWhere('tenant_id', $activeTenantId);
        if ($activeSummary === null) {
            $activeTenant = Tenant::query()->find($activeTenantId);
            if ($activeTenant !== null) {
                $activeSummary = $this->summarizeTenant($activeTenant);
                $clients[] = $activeSummary;
            }
        }

        $fiscalYears = FiscalYear::withoutGlobalScopes()
            ->where('tenant_id', $activeTenantId)
            ->orderByDesc('year_code')
            ->get(['id', 'year_code', 'start_date', 'end_date', 'status']);

        $periods = FiscalPeriod::withoutGlobalScopes()
            ->where('tenant_id', $activeTenantId)
            ->orderBy('start_date')
            ->get();

        return [
            'active_tenant_id' => $activeTenantId,
            'active_client' => $activeSummary,
            'clients' => $clients,
            'fiscal_years' => $fiscalYears->map(static fn (FiscalYear $fy): array => [
                'id' => (string) $fy->id,
                'year_code' => (string) $fy->year_code,
                'start_date' => $fy->start_date?->toDateString(),
                'end_date' => $fy->end_date?->toDateString(),
                'status' => (string) $fy->status,
            ])->values()->all(),
            'fiscal_periods' => $periods->map(static fn (FiscalPeriod $p): array => [
                'id' => (string) $p->id,
                'fiscal_year_id' => (string) $p->fiscal_year_id,
                'period_number' => (int) $p->period_number,
                'period_name' => (string) $p->period_name,
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
                'status' => (string) $p->status,
                'is_fiduciary_locked' => $p->isFiduciaryLocked(),
                'fiduciary_locked_at' => $p->fiduciary_locked_at?->toIso8601String(),
                'fiduciary_lock_reason' => $p->fiduciary_lock_reason,
            ])->values()->all(),
            'export_formats' => array_map(
                static fn (StudioExportFormat $fmt): array => $fmt->toMetadata(),
                StudioExportFormat::cases()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeTenant(Tenant $tenant): array
    {
        $tid = (string) $tenant->id;

        $totalPeriods = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tid)->count();
        $lockedByFiduciary = FiscalPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->where(function ($q): void {
                $q->where('status', 'locked_by_fiduciary')->orWhereNotNull('fiduciary_locked_at');
            })
            ->count();
        $closedPeriods = FiscalPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->where('status', 'closed')
            ->count();
        $latestLockedPeriod = FiscalPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->where('status', 'locked_by_fiduciary')
            ->orderByDesc('end_date')
            ->value('period_name');

        $postedTotal = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->whereIn('status', ['posted', 'reversed'])
            ->count();
        $verifiedTotal = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->whereIn('status', ['posted', 'reversed'])
            ->where('fiduciary_verified', true)
            ->count();
        $unverifiedTotal = max(0, $postedTotal - $verifiedTotal);

        $openNotes = FiduciaryEntryNote::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->where('status', 'open')
            ->count();
        $resolvedNotes = FiduciaryEntryNote::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->where('status', 'resolved')
            ->count();

        $postableAccounts = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->count();
        $mappedAccounts = FiduciaryAccountTranscoding::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->where('is_active', true)
            ->distinct('source_account_id')
            ->count('source_account_id');
        $unmappedAccounts = max(0, $postableAccounts - $mappedAccounts);

        $exportRunsCount = FiduciaryExportRun::withoutGlobalScopes()->where('tenant_id', $tid)->count();
        $lastExportAt = FiduciaryExportRun::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->latest('created_at')
            ->value('created_at');

        $country = strtoupper((string) ($tenant->country ?? 'IT'));

        return [
            'tenant_id' => $tid,
            'name' => (string) $tenant->name,
            'legal_name' => (string) ($tenant->legal_name ?: $tenant->name),
            'jurisdiction' => $country === 'CH' ? 'CH' : 'IT',
            'country' => $country,
            'currency' => (string) ($tenant->default_currency ?: ($country === 'CH' ? 'CHF' : 'EUR')),
            'vat_number' => $tenant->vat_number,
            'uid_number' => $tenant->uid_number,
            'role' => $tenant->pivot->role ?? 'external_fiduciary',
            'periods_total' => $totalPeriods,
            'periods_fiduciary_locked' => $lockedByFiduciary,
            'periods_closed' => $closedPeriods,
            'periods_open' => max(0, $totalPeriods - $lockedByFiduciary - $closedPeriods),
            'latest_locked_period' => $latestLockedPeriod,
            'posted_entries_count' => $postedTotal,
            'verified_entries_count' => $verifiedTotal,
            'unverified_entries_count' => $unverifiedTotal,
            'verification_progress_percent' => $postedTotal > 0 ? (int) round(($verifiedTotal / $postedTotal) * 100) : 100,
            'open_notes_count' => $openNotes,
            'resolved_notes_count' => $resolvedNotes,
            'postable_accounts_count' => $postableAccounts,
            'unmapped_accounts_count' => $unmappedAccounts,
            'export_runs_count' => $exportRunsCount,
            'last_export_at' => $lastExportAt !== null ? (string) $lastExportAt : null,
        ];
    }
}
