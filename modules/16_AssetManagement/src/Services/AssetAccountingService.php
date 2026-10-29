<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Str;
use Modules\AssetManagement\Contracts\AssetAccountingServiceInterface;
use Modules\AssetManagement\Events\AssetDepreciationPostedEvent;
use Modules\AssetManagement\Events\AssetDepreciationReadyEvent;
use Modules\AssetManagement\Events\AssetDisposalReadyEvent;
use Modules\AssetManagement\Events\AssetImpairmentReadyEvent;
use Modules\AssetManagement\Events\AssetRevaluationReadyEvent;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;

final readonly class AssetAccountingService implements AssetAccountingServiceInterface
{
    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private EventDispatcher $dispatcher
    ) {}

    public function postCapitalizationEntry(FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $amount = (float) $asset->capitalized_cost;
        if ($amount <= 0.0) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $companyId = (string) ($asset->company_id ?? $tenantId);
        $category = AssetCategory::find($asset->category_id);

        $assetAccCode = $category?->gl_asset_account_code ?? '1600';
        $clearingAccCode = $category?->gl_clearing_account_code ?? '2150';

        $period = $this->resolveOrProvisionFiscalPeriod($tenantId, $companyId);
        $assetAcc = $this->resolveAccount($tenantId, $assetAccCode, 'Fixed Assets - Property, Plant & Equipment', 'asset', 'debit');
        $clearingAcc = $this->resolveAccount($tenantId, $clearingAccCode, 'Fixed Asset Acquisition Clearing', 'liability', 'credit');

        return $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'EAM-CAP-'.strtoupper(Str::random(6)),
            'posting_date' => $asset->capitalization_date?->format('Y-m-d') ?? date('Y-m-d'),
            'document_date' => $asset->acquisition_date?->format('Y-m-d') ?? date('Y-m-d'),
            'source_document_type' => 'eam_fixed_asset',
            'source_document_id' => (string) $asset->id,
            'journal_type' => 'standard',
            'description' => "Asset Capitalization: {$asset->asset_number} - {$asset->name}",
            'lines' => [
                [
                    'account_id' => $assetAcc->id,
                    'debit_amount' => $amount,
                    'credit_amount' => 0.0,
                    'description' => "Dr Fixed Asset ({$asset->asset_number})",
                ],
                [
                    'account_id' => $clearingAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => $amount,
                    'description' => "Cr Asset Clearing ({$asset->asset_number})",
                ],
            ],
        ], $actorUserId);
    }

    public function postDepreciationEntry(DepreciationSchedule $schedule, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $amount = (float) $schedule->depreciation_amount;
        if ($amount <= 0.0) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $companyId = (string) ($asset->company_id ?? $tenantId);
        $category = AssetCategory::find($asset->category_id);

        $deprExpCode = $category?->gl_depreciation_expense_code ?? '6400';
        $accumDeprCode = $category?->gl_accumulated_depreciation_code ?? '1650';

        $readyEvent = new AssetDepreciationReadyEvent(
            scheduleId: (string) $schedule->id,
            assetId: (string) $asset->id,
            periodCode: (string) $schedule->period_code,
            depreciationAmount: $amount,
            glDepreciationExpenseCode: $deprExpCode,
            glAccumulatedDepreciationCode: $accumDeprCode,
            tenantId: $tenantId,
            companyId: $companyId
        );
        $this->dispatcher->publish($readyEvent);
        event($readyEvent);

        $period = $this->resolveOrProvisionFiscalPeriod($tenantId, $companyId);
        $expAcc = $this->resolveAccount($tenantId, $deprExpCode, 'Depreciation Expense', 'expense', 'debit');
        $accumAcc = $this->resolveAccount($tenantId, $accumDeprCode, 'Accumulated Depreciation', 'asset', 'credit');

        $entry = $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'EAM-DEP-'.strtoupper(Str::random(6)),
            'posting_date' => date('Y-m-d'),
            'document_date' => date('Y-m-d'),
            'source_document_type' => 'eam_depreciation_schedule',
            'source_document_id' => (string) $schedule->id,
            'journal_type' => 'standard',
            'description' => "Asset Depreciation [{$schedule->period_code}]: {$asset->asset_number}",
            'lines' => [
                [
                    'account_id' => $expAcc->id,
                    'debit_amount' => $amount,
                    'credit_amount' => 0.0,
                    'description' => "Dr Depreciation Expense ({$asset->asset_number})",
                ],
                [
                    'account_id' => $accumAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => $amount,
                    'description' => "Cr Accumulated Depreciation ({$asset->asset_number})",
                ],
            ],
        ], $actorUserId);

        $schedule->update([
            'status' => 'posted',
            'journal_entry_id' => (string) $entry->id,
            'posted_at' => now(),
        ]);

        $postedEvent = new AssetDepreciationPostedEvent(
            scheduleId: (string) $schedule->id,
            assetId: (string) $asset->id,
            journalEntryId: (string) $entry->id,
            periodCode: (string) $schedule->period_code,
            depreciationAmount: $amount,
            newCarryingAmount: (float) $schedule->closing_book_value,
            tenantId: $tenantId,
            companyId: $companyId
        );
        $this->dispatcher->publish($postedEvent);
        event($postedEvent);

        return $entry;
    }

    public function postImpairmentEntry(AssetValuation $valuation, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $impairmentAmount = (float) $valuation->impairment_loss_amount;
        if ($impairmentAmount <= 0.0) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $companyId = (string) ($asset->company_id ?? $tenantId);
        $category = AssetCategory::find($asset->category_id);

        $impairmentCode = $category?->gl_impairment_account_code ?? '6450';
        $assetCode = $category?->gl_asset_account_code ?? '1600';

        $readyEvent = new AssetImpairmentReadyEvent(
            valuationId: (string) $valuation->id,
            assetId: (string) $asset->id,
            impairmentLossAmount: $impairmentAmount,
            glImpairmentAccountCode: $impairmentCode,
            glAssetAccountCode: $assetCode,
            valuationDate: $valuation->valuation_date?->format('Y-m-d') ?? date('Y-m-d'),
            tenantId: $tenantId,
            companyId: $companyId
        );
        $this->dispatcher->publish($readyEvent);
        event($readyEvent);

        $period = $this->resolveOrProvisionFiscalPeriod($tenantId, $companyId);
        $impairmentAcc = $this->resolveAccount($tenantId, $impairmentCode, 'Asset Impairment Expense', 'expense', 'debit');
        $assetAcc = $this->resolveAccount($tenantId, $assetCode, 'Fixed Assets - Property, Plant & Equipment', 'asset', 'debit');

        $entry = $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'EAM-IMP-'.strtoupper(Str::random(6)),
            'posting_date' => $valuation->valuation_date?->format('Y-m-d') ?? date('Y-m-d'),
            'document_date' => $valuation->valuation_date?->format('Y-m-d') ?? date('Y-m-d'),
            'source_document_type' => 'eam_asset_valuation',
            'source_document_id' => (string) $valuation->id,
            'journal_type' => 'standard',
            'description' => "IAS 36 Asset Impairment: {$asset->asset_number}",
            'lines' => [
                [
                    'account_id' => $impairmentAcc->id,
                    'debit_amount' => $impairmentAmount,
                    'credit_amount' => 0.0,
                    'description' => "Dr Impairment Expense ({$asset->asset_number})",
                ],
                [
                    'account_id' => $assetAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => $impairmentAmount,
                    'description' => "Cr Asset Value ({$asset->asset_number})",
                ],
            ],
        ], $actorUserId);

        $valuation->update([
            'status' => 'posted',
            'journal_entry_id' => (string) $entry->id,
        ]);

        return $entry;
    }

    public function postRevaluationEntry(AssetValuation $valuation, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $delta = (float) $valuation->adjustment_amount;
        $absDelta = abs($delta);
        if ($absDelta <= 0.0) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $companyId = (string) ($asset->company_id ?? $tenantId);
        $category = AssetCategory::find($asset->category_id);

        $assetCode = $category?->gl_asset_account_code ?? '1600';
        $reserveCode = $category?->gl_revaluation_reserve_code ?? '3300';

        $readyEvent = new AssetRevaluationReadyEvent(
            valuationId: (string) $valuation->id,
            assetId: (string) $asset->id,
            revaluationDelta: $delta,
            glAssetAccountCode: $assetCode,
            glRevaluationReserveCode: $reserveCode,
            valuationDate: $valuation->valuation_date?->format('Y-m-d') ?? date('Y-m-d'),
            tenantId: $tenantId,
            companyId: $companyId
        );
        $this->dispatcher->publish($readyEvent);
        event($readyEvent);

        $period = $this->resolveOrProvisionFiscalPeriod($tenantId, $companyId);
        $assetAcc = $this->resolveAccount($tenantId, $assetCode, 'Fixed Assets - Property, Plant & Equipment', 'asset', 'debit');
        $reserveAcc = $this->resolveAccount($tenantId, $reserveCode, 'Asset Revaluation Reserve', 'equity', 'credit');

        $isUpward = $delta > 0;

        $entry = $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'EAM-REV-'.strtoupper(Str::random(6)),
            'posting_date' => $valuation->valuation_date?->format('Y-m-d') ?? date('Y-m-d'),
            'document_date' => $valuation->valuation_date?->format('Y-m-d') ?? date('Y-m-d'),
            'source_document_type' => 'eam_asset_valuation',
            'source_document_id' => (string) $valuation->id,
            'journal_type' => 'standard',
            'description' => "IAS 16 Asset Revaluation: {$asset->asset_number}",
            'lines' => [
                [
                    'account_id' => $isUpward ? $assetAcc->id : $reserveAcc->id,
                    'debit_amount' => $absDelta,
                    'credit_amount' => 0.0,
                    'description' => $isUpward ? "Dr Asset Value ({$asset->asset_number})" : "Dr Revaluation Reserve ({$asset->asset_number})",
                ],
                [
                    'account_id' => $isUpward ? $reserveAcc->id : $assetAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => $absDelta,
                    'description' => $isUpward ? "Cr Revaluation Reserve ({$asset->asset_number})" : "Cr Asset Value ({$asset->asset_number})",
                ],
            ],
        ], $actorUserId);

        $valuation->update([
            'status' => 'posted',
            'journal_entry_id' => (string) $entry->id,
        ]);

        return $entry;
    }

    public function postDisposalEntry(AssetDisposal $disposal, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $assetCost = round((float) $disposal->asset_cost_at_disposal, 2);
        if ($assetCost <= 0.0) {
            return null;
        }

        $accumDepr = round((float) $disposal->accumulated_depreciation_at_disposal, 2);
        $netProceeds = round(max(0.0, (float) $disposal->proceeds_amount - (float) $disposal->disposal_costs), 2);
        $carryingAmount = round($assetCost - $accumDepr, 2);
        $gainLoss = round($netProceeds - $carryingAmount, 2);

        $tenantId = (string) $asset->tenant_id;
        $companyId = (string) ($asset->company_id ?? $tenantId);
        $category = AssetCategory::find($asset->category_id);

        $assetCode = $category?->gl_asset_account_code ?? '1600';
        $accumCode = $category?->gl_accumulated_depreciation_code ?? '1650';
        $gainLossCode = $category?->gl_disposal_gain_loss_code ?? '7500';
        $cashCode = '1100';

        $readyEvent = new AssetDisposalReadyEvent(
            disposalId: (string) $disposal->id,
            assetId: (string) $asset->id,
            assetCost: $assetCost,
            accumulatedDepreciation: $accumDepr,
            proceedsAmount: $netProceeds,
            gainLossAmount: $gainLoss,
            glAssetAccountCode: $assetCode,
            glAccumulatedDepreciationCode: $accumCode,
            glDisposalGainLossCode: $gainLossCode,
            disposalDate: $disposal->disposal_date?->format('Y-m-d') ?? date('Y-m-d'),
            tenantId: $tenantId,
            companyId: $companyId
        );
        $this->dispatcher->publish($readyEvent);
        event($readyEvent);

        $period = $this->resolveOrProvisionFiscalPeriod($tenantId, $companyId);
        $assetAcc = $this->resolveAccount($tenantId, $assetCode, 'Fixed Assets - Property, Plant & Equipment', 'asset', 'debit');
        $accumAcc = $this->resolveAccount($tenantId, $accumCode, 'Accumulated Depreciation', 'asset', 'credit');
        $gainLossAcc = $this->resolveAccount($tenantId, $gainLossCode, 'Gain / Loss on Asset Disposal', 'revenue', 'credit');
        $cashAcc = $this->resolveAccount($tenantId, $cashCode, 'Cash & Disposal Proceeds Clearing', 'asset', 'debit');

        $lines = [];

        // 1. Dr Accumulated Depreciation
        if ($accumDepr > 0.0) {
            $lines[] = [
                'account_id' => $accumAcc->id,
                'debit_amount' => $accumDepr,
                'credit_amount' => 0.0,
                'description' => "Dr Accumulated Depreciation ({$asset->asset_number})",
            ];
        }

        // 2. Dr Cash / Proceeds
        if ($netProceeds > 0.0) {
            $lines[] = [
                'account_id' => $cashAcc->id,
                'debit_amount' => $netProceeds,
                'credit_amount' => 0.0,
                'description' => "Dr Cash / Disposal Proceeds ({$asset->asset_number})",
            ];
        }

        // 3. If Loss on Disposal (gainLoss < 0), Dr Loss on Disposal
        if ($gainLoss < 0.0) {
            $lines[] = [
                'account_id' => $gainLossAcc->id,
                'debit_amount' => abs($gainLoss),
                'credit_amount' => 0.0,
                'description' => "Dr Loss on Disposal ({$asset->asset_number})",
            ];
        }

        // 4. Cr Asset Cost
        $lines[] = [
            'account_id' => $assetAcc->id,
            'debit_amount' => 0.0,
            'credit_amount' => $assetCost,
            'description' => "Cr Asset Cost ({$asset->asset_number})",
        ];

        // 5. If Gain on Disposal (gainLoss > 0), Cr Gain on Disposal
        if ($gainLoss > 0.0) {
            $lines[] = [
                'account_id' => $gainLossAcc->id,
                'debit_amount' => 0.0,
                'credit_amount' => $gainLoss,
                'description' => "Cr Gain on Disposal ({$asset->asset_number})",
            ];
        }

        $entry = $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'EAM-DSP-'.strtoupper(Str::random(6)),
            'posting_date' => $disposal->disposal_date?->format('Y-m-d') ?? date('Y-m-d'),
            'document_date' => $disposal->disposal_date?->format('Y-m-d') ?? date('Y-m-d'),
            'source_document_type' => 'eam_asset_disposal',
            'source_document_id' => (string) $disposal->id,
            'journal_type' => 'standard',
            'description' => "Asset Disposal ({$disposal->disposal_type}): {$asset->asset_number}",
            'lines' => $lines,
        ], $actorUserId);

        $disposal->update([
            'journal_entry_id' => (string) $entry->id,
        ]);

        return $entry;
    }

    private function resolveOrProvisionFiscalPeriod(string $tenantId, string $companyId): FiscalPeriod
    {
        $period = FiscalPeriod::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->first();

        if ($period !== null) {
            return $period;
        }

        $yearCode = 'FY'.date('Y');
        $fiscalYear = FiscalYear::where('tenant_id', $tenantId)
            ->where('year_code', $yearCode)
            ->first();

        if ($fiscalYear === null) {
            $fiscalYear = FiscalYear::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'year_code' => $yearCode,
                'start_date' => date('Y-01-01'),
                'end_date' => date('Y-12-31'),
                'status' => 'open',
                'is_locked' => false,
            ]);
        }

        return FiscalPeriod::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'fiscal_year_id' => $fiscalYear->id,
                'period_number' => (int) date('n'),
            ],
            [
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'period_name' => date('F Y'),
                'start_date' => date('Y-m-01'),
                'end_date' => date('Y-m-t'),
                'status' => 'open',
            ]
        );
    }

    private function resolveAccount(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
    {
        $existing = ChartOfAccount::where('tenant_id', $tenantId)
            ->where(function ($q) use ($code): void {
                $q->where('code', $code)->orWhere('account_code', $code);
            })
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ChartOfAccount::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'account_code' => $code,
            'name' => $name,
            'type' => $type,
            'account_class' => $type,
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'standard' => 'it_general_ledger',
            'currency' => 'EUR',
            'is_active' => true,
        ]);
    }
}
