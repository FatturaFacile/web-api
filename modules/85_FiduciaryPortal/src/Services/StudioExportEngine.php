<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\FiduciaryPortal\Models\FiduciaryExportRun;
use Modules\FiduciaryPortal\Services\Exporters\StudioExportRegistry;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

final readonly class StudioExportEngine
{
    public function __construct(
        private StudioExportRegistry $registry,
        private FiduciaryTranscodingService $transcoding,
        private FiduciaryAuditService $audit,
    ) {}

    /**
     * Pre-export validation & diagnostics: checks date range, period lock states, unmapped accounts/VAT codes, and totals.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function validate(string $tenantId, array $params): array
    {
        $format = StudioExportFormat::from((string) $params['format_code']);
        $from = CarbonImmutable::parse((string) $params['from_date']);
        $to = CarbonImmutable::parse((string) $params['to_date']);
        $onlyVerified = (bool) ($params['only_verified'] ?? false);
        $applyTranscoding = (bool) ($params['apply_transcoding'] ?? true);

        $entries = $this->loadEntries($tenantId, $from, $to, $onlyVerified, $params['fiscal_period_id'] ?? null);
        $transcodingMap = $this->transcoding->mapForTenant($tenantId, $format->value);

        $unmappedAccounts = [];
        $unmappedVatCodes = [];
        $nonNumericDatevAccounts = [];
        $linesCount = 0;
        $totalDebit = '0';
        $totalCredit = '0';

        /** @var JournalEntry $entry */
        foreach ($entries as $entry) {
            /** @var JournalLine $line */
            foreach ($entry->lines as $line) {
                $linesCount++;
                $totalDebit = Decimal::add($totalDebit, (string) ($line->functional_debit ?? $line->debit_amount));
                $totalCredit = Decimal::add($totalCredit, (string) ($line->functional_credit ?? $line->credit_amount));

                $rule = $transcodingMap[(string) $line->account_id] ?? null;
                $sourceCode = (string) ($line->account?->account_code ?? '');

                if ($applyTranscoding && ($rule === null || trim((string) $rule->studio_account_code) === '')) {
                    $unmappedAccounts[(string) $line->account_id] = [
                        'account_id' => (string) $line->account_id,
                        'account_code' => $sourceCode,
                        'account_name' => (string) ($line->account?->name ?? ''),
                    ];
                }

                if ($line->vat_code_id !== null && $line->vatCode !== null) {
                    if ($applyTranscoding && ($rule === null || trim((string) ($rule->studio_vat_code ?? '')) === '')) {
                        $unmappedVatCodes[(string) $line->vatCode->code] = (string) $line->vatCode->code;
                    }
                }

                if ($format === StudioExportFormat::DatevKoinos) {
                    $effectiveCode = ($applyTranscoding && $rule !== null && trim((string) $rule->studio_account_code) !== '')
                        ? (string) $rule->studio_account_code
                        : $sourceCode;
                    if (preg_match('/^\d+$/', $effectiveCode) !== 1) {
                        $nonNumericDatevAccounts[$effectiveCode] = $effectiveCode;
                    }
                }
            }
        }

        $periods = FiscalPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->orderBy('start_date')
            ->get(['id', 'period_name', 'status', 'fiduciary_locked_at']);

        $openPeriods = $periods->filter(static fn (FiscalPeriod $p): bool => $p->status === 'open')->pluck('period_name')->values()->all();
        $lockedPeriods = $periods->filter(static fn (FiscalPeriod $p): bool => $p->isFiduciaryLocked())->pluck('period_name')->values()->all();

        $warnings = [];
        if ($entries->isEmpty()) {
            $warnings[] = 'NO_ENTRIES_IN_RANGE';
        }
        if ($unmappedAccounts !== []) {
            $warnings[] = 'UNMAPPED_ACCOUNTS_FOUND';
        }
        if ($unmappedVatCodes !== []) {
            $warnings[] = 'UNMAPPED_VAT_CODES_FOUND';
        }
        if ($nonNumericDatevAccounts !== []) {
            $warnings[] = 'DATEV_NON_NUMERIC_ACCOUNTS';
        }
        if ($openPeriods !== []) {
            $warnings[] = 'PERIODS_STILL_OPEN';
        }

        $isBalanced = Decimal::compare(Decimal::round2($totalDebit), Decimal::round2($totalCredit)) === 0;
        if (! $isBalanced && $entries->isNotEmpty()) {
            $warnings[] = 'UNBALANCED_LEDGER_SELECTION';
        }

        return [
            'format_code' => $format->value,
            'jurisdiction' => $format->jurisdiction(),
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'can_export' => $entries->isNotEmpty() && $isBalanced,
            'entries_count' => $entries->count(),
            'lines_count' => $linesCount,
            'total_debit' => Decimal::round2($totalDebit),
            'total_credit' => Decimal::round2($totalCredit),
            'is_balanced' => $isBalanced,
            'unmapped_accounts_count' => count($unmappedAccounts),
            'unmapped_accounts' => array_values($unmappedAccounts),
            'unmapped_vat_codes' => array_values($unmappedVatCodes),
            'non_numeric_datev_accounts' => array_values($nonNumericDatevAccounts),
            'open_periods' => $openPeriods,
            'locked_periods' => $lockedPeriods,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function generate(string $tenantId, array $params, ?User $actor = null): FiduciaryExportRun
    {
        $diagnostics = $this->validate($tenantId, $params);
        $strictMapping = (bool) ($params['strict_mapping'] ?? false);

        if ($strictMapping && $diagnostics['unmapped_accounts_count'] > 0) {
            throw ValidationException::withMessages([
                'apply_transcoding' => ['Unmapped accounts found in the selected period. Complete the Chart of Accounts transcoding matrix before exporting.'],
            ]);
        }

        $format = StudioExportFormat::from((string) $params['format_code']);
        $from = CarbonImmutable::parse((string) $params['from_date']);
        $to = CarbonImmutable::parse((string) $params['to_date']);
        $onlyVerified = (bool) ($params['only_verified'] ?? false);
        $applyTranscoding = (bool) ($params['apply_transcoding'] ?? true);

        $tenant = Tenant::query()->findOrFail($tenantId);
        $currency = TenantAccountingCurrency::of($tenantId);
        $entries = $this->loadEntries($tenantId, $from, $to, $onlyVerified, $params['fiscal_period_id'] ?? null);
        $transcodingMap = $this->transcoding->mapForTenant($tenantId, $format->value);

        $strategy = $this->registry->resolve($format);
        $generated = $strategy->generate($tenant, $entries, $transcodingMap, $from, $to, $currency, [
            'apply_transcoding' => $applyTranscoding,
            'consultant_number' => $params['consultant_number'] ?? 1,
            'client_number' => $params['client_number'] ?? 1,
            'account_length' => $params['account_length'] ?? 4,
        ]);

        $run = FiduciaryExportRun::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'fiscal_year_id' => $params['fiscal_year_id'] ?? null,
            'fiscal_period_id' => $params['fiscal_period_id'] ?? null,
            'format_code' => $format->value,
            'jurisdiction' => $format->jurisdiction(),
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'only_verified' => $onlyVerified,
            'apply_transcoding' => $applyTranscoding,
            'entries_count' => $diagnostics['entries_count'],
            'lines_count' => $diagnostics['lines_count'],
            'unmapped_accounts_count' => $diagnostics['unmapped_accounts_count'],
            'total_debit' => $diagnostics['total_debit'],
            'total_credit' => $diagnostics['total_credit'],
            'currency' => $currency,
            'file_name' => $generated['file_name'],
            'mime_type' => $generated['mime_type'],
            'content_payload' => $generated['content'],
            'diagnostics_json' => $diagnostics,
            'exported_by' => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
            'created_by' => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
        ]);

        $this->audit->record(
            tenantId: $tenantId,
            action: 'export_generated',
            summary: "Generated [{$format->value}] studio export ({$diagnostics['entries_count']} entries, {$generated['file_name']}).",
            actor: $actor,
            targetType: 'fiduciary_export_run',
            targetId: (string) $run->id,
            targetReference: $generated['file_name'],
            metadata: [
                'format_code' => $format->value,
                'entries_count' => $diagnostics['entries_count'],
                'unmapped_accounts_count' => $diagnostics['unmapped_accounts_count'],
            ],
            auditModel: $run,
        );

        return $run;
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    private function loadEntries(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, bool $onlyVerified, mixed $fiscalPeriodId): Collection
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['posted', 'reversed'])
            ->whereDate('posting_date', '>=', $from->toDateString())
            ->whereDate('posting_date', '<=', $to->toDateString())
            ->when(is_string($fiscalPeriodId) && $fiscalPeriodId !== '', fn ($q) => $q->where('fiscal_period_id', $fiscalPeriodId))
            ->when($onlyVerified, fn ($q) => $q->where('fiduciary_verified', true))
            ->with([
                'lines' => fn ($q) => $q->withoutGlobalScopes()
                    ->with(['account:id,account_code,name,standard', 'vatCode:id,code,rate', 'costCenter:id,code,name'])
                    ->orderBy('line_number'),
            ])
            ->orderBy('posting_date')
            ->orderBy('journal_number')
            ->orderBy('entry_number')
            ->get();
    }
}
