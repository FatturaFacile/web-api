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
use Modules\AssetManagement\Exceptions\AssetOperationException;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\AssetDisposal;
use Modules\AssetManagement\Models\AssetValuation;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\AssetManagement\Support\AssetLedger;
use Modules\AssetManagement\Support\DepreciationPeriod;
use Modules\AssetManagement\Support\Money;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Posts the asset lifecycle to the General Ledger through its double-entry posting service. Accounts come from the
 * category of the asset (codes created on first use, as the other posting listeners do); the fiscal period is the
 * open one containing the document date, locked periods of the FA subledger are refused.
 */
final readonly class AssetAccountingService implements AssetAccountingServiceInterface
{
    /** Subledger code of fixed assets for period locks. */
    private const string Subledger = 'FA';

    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private EventDispatcher $dispatcher,
        private FiscalPeriodResolver $periods,
    ) {}

    public function postCapitalizationEntry(FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $amount = Money::round($asset->capitalized_cost);
        if (! Money::isPositive($amount)) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $category = $this->categoryOf($asset);
        $date = $asset->capitalization_date?->format('Y-m-d') ?? now()->toDateString();

        $assetAccount = $this->account($tenantId, $category->gl_asset_account_code ?? '1600', 'Fixed assets', 'asset', 'debit');
        $creditAccount = $this->capitalizationCreditAccount($asset, $category);

        return $this->post($asset, $date, 'CAP', 'eam_fixed_asset', (string) $asset->id, "Asset capitalization: {$asset->asset_number} - {$asset->name}", [
            $this->line($assetAccount, $amount, '0', "Dr fixed asset ({$asset->asset_number})"),
            $this->line($creditAccount, '0', $amount, "Cr acquisition clearing ({$asset->asset_number})"),
        ], $actorUserId);
    }

    public function postDepreciationEntry(DepreciationSchedule $schedule, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $amount = Money::round($schedule->depreciation_amount);
        if (! Money::isPositive($amount) || $schedule->journal_entry_id !== null || $schedule->ledger_type !== AssetLedger::Statutory) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $category = $this->categoryOf($asset);
        $expenseCode = $category->gl_depreciation_expense_code ?? '6400';
        $accumulatedCode = $category->gl_accumulated_depreciation_code ?? '1650';
        $date = DepreciationPeriod::fromCode((string) $schedule->period_code, (string) $schedule->period_type)->end->toDateString();

        $this->dispatcher->publishAndDispatch(new AssetDepreciationReadyEvent(
            scheduleId: (string) $schedule->id,
            assetId: (string) $asset->id,
            periodCode: (string) $schedule->period_code,
            depreciationAmount: Money::toFloat($amount),
            glDepreciationExpenseCode: $expenseCode,
            glAccumulatedDepreciationCode: $accumulatedCode,
            tenantId: $tenantId,
            companyId: $this->companyOf($asset)
        ));

        $expense = $this->account($tenantId, $expenseCode, 'Depreciation expense', 'expense', 'debit');
        $accumulated = $this->account($tenantId, $accumulatedCode, 'Accumulated depreciation', 'asset', 'credit');

        $entry = $this->post($asset, $date, 'DEP', 'eam_depreciation_schedule', (string) $schedule->id, "Asset depreciation [{$schedule->period_code}]: {$asset->asset_number}", [
            $this->line($expense, $amount, '0', "Dr depreciation expense ({$asset->asset_number})"),
            $this->line($accumulated, '0', $amount, "Cr accumulated depreciation ({$asset->asset_number})"),
        ], $actorUserId);

        $schedule->update(['status' => 'posted', 'journal_entry_id' => (string) $entry->id, 'posted_at' => now()]);

        $this->dispatcher->publishAndDispatch(new AssetDepreciationPostedEvent(
            scheduleId: (string) $schedule->id,
            assetId: (string) $asset->id,
            journalEntryId: (string) $entry->id,
            periodCode: (string) $schedule->period_code,
            depreciationAmount: Money::toFloat($amount),
            newCarryingAmount: Money::toFloat($schedule->closing_book_value),
            tenantId: $tenantId,
            companyId: $this->companyOf($asset)
        ));

        return $entry;
    }

    public function postImpairmentEntry(AssetValuation $valuation, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $amount = Money::round($valuation->impairment_loss_amount);
        if (! Money::isPositive($amount) || $valuation->journal_entry_id !== null) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $category = $this->categoryOf($asset);
        $impairmentCode = $category->gl_impairment_account_code ?? '6450';
        $assetCode = $category->gl_asset_account_code ?? '1600';
        $date = $valuation->valuation_date?->format('Y-m-d') ?? now()->toDateString();

        $this->dispatcher->publishAndDispatch(new AssetImpairmentReadyEvent(
            valuationId: (string) $valuation->id,
            assetId: (string) $asset->id,
            impairmentLossAmount: Money::toFloat($amount),
            glImpairmentAccountCode: $impairmentCode,
            glAssetAccountCode: $assetCode,
            valuationDate: $date,
            tenantId: $tenantId,
            companyId: $this->companyOf($asset)
        ));

        $entry = $this->post($asset, $date, 'IMP', 'eam_asset_valuation', (string) $valuation->id, "Asset impairment: {$asset->asset_number}", [
            $this->line($this->account($tenantId, $impairmentCode, 'Asset impairment losses', 'expense', 'debit'), $amount, '0', "Dr impairment loss ({$asset->asset_number})"),
            $this->line($this->account($tenantId, $assetCode, 'Fixed assets', 'asset', 'debit'), '0', $amount, "Cr fixed asset ({$asset->asset_number})"),
        ], $actorUserId);

        $valuation->update(['status' => 'posted', 'journal_entry_id' => (string) $entry->id]);

        return $entry;
    }

    public function postRevaluationEntry(AssetValuation $valuation, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $delta = Money::round($valuation->adjustment_amount);
        if (Money::isZero($delta) || $valuation->journal_entry_id !== null) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $category = $this->categoryOf($asset);
        $assetCode = $category->gl_asset_account_code ?? '1600';
        $reserveCode = $category->gl_revaluation_reserve_code ?? '3300';
        $date = $valuation->valuation_date?->format('Y-m-d') ?? now()->toDateString();
        $total = Money::abs($delta);
        $reservePart = Money::round(Money::abs($valuation->revaluation_reserve_delta));
        $profitLossPart = Money::round(Money::sub($total, $reservePart));
        $isUpward = Money::isPositive($delta);

        $this->dispatcher->publishAndDispatch(new AssetRevaluationReadyEvent(
            valuationId: (string) $valuation->id,
            assetId: (string) $asset->id,
            revaluationDelta: Money::toFloat($delta),
            glAssetAccountCode: $assetCode,
            glRevaluationReserveCode: $reserveCode,
            valuationDate: $date,
            tenantId: $tenantId,
            companyId: $this->companyOf($asset)
        ));

        $assetAccount = $this->account($tenantId, $assetCode, 'Fixed assets', 'asset', 'debit');
        $reserveAccount = $this->account($tenantId, $reserveCode, 'Asset revaluation reserve', 'equity', 'credit');
        $impairmentAccount = $this->account($tenantId, $category->gl_impairment_account_code ?? '6450', 'Asset impairment losses', 'expense', 'debit');

        $lines = [$isUpward
            ? $this->line($assetAccount, Money::round($total), '0', "Dr fixed asset ({$asset->asset_number})")
            : $this->line($assetAccount, '0', Money::round($total), "Cr fixed asset ({$asset->asset_number})")];

        if (Money::isPositive($reservePart)) {
            $lines[] = $isUpward
                ? $this->line($reserveAccount, '0', $reservePart, "Cr revaluation reserve ({$asset->asset_number})")
                : $this->line($reserveAccount, $reservePart, '0', "Dr revaluation reserve ({$asset->asset_number})");
        }

        if (Money::isPositive($profitLossPart)) {
            $lines[] = $isUpward
                ? $this->line($impairmentAccount, '0', $profitLossPart, "Cr reversal of earlier losses ({$asset->asset_number})")
                : $this->line($impairmentAccount, $profitLossPart, '0', "Dr revaluation loss ({$asset->asset_number})");
        }

        $entry = $this->post($asset, $date, 'REV', 'eam_asset_valuation', (string) $valuation->id, "Asset revaluation: {$asset->asset_number}", $lines, $actorUserId);
        $valuation->update(['status' => 'posted', 'journal_entry_id' => (string) $entry->id]);

        return $entry;
    }

    public function postImpairmentReversalEntry(AssetValuation $valuation, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $amount = Money::round($valuation->adjustment_amount);
        if (! Money::isPositive($amount) || $valuation->journal_entry_id !== null) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $category = $this->categoryOf($asset);
        $date = $valuation->valuation_date?->format('Y-m-d') ?? now()->toDateString();

        $entry = $this->post($asset, $date, 'IRV', 'eam_asset_valuation', (string) $valuation->id, "Impairment reversal: {$asset->asset_number}", [
            $this->line($this->account($tenantId, $category->gl_asset_account_code ?? '1600', 'Fixed assets', 'asset', 'debit'), $amount, '0', "Dr fixed asset ({$asset->asset_number})"),
            $this->line($this->account($tenantId, $category->gl_impairment_account_code ?? '6450', 'Asset impairment losses', 'expense', 'debit'), '0', $amount, "Cr impairment reversal ({$asset->asset_number})"),
        ], $actorUserId);

        $valuation->update(['status' => 'posted', 'journal_entry_id' => (string) $entry->id]);

        return $entry;
    }

    public function postDisposalEntry(AssetDisposal $disposal, FixedAsset $asset, ?string $actorUserId = null): ?JournalEntry
    {
        $gross = Money::round($disposal->asset_cost_at_disposal);
        if (! Money::isPositive($gross) || $disposal->journal_entry_id !== null) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $category = $this->categoryOf($asset);
        $accumulated = Money::round($disposal->accumulated_depreciation_at_disposal);
        $netProceeds = Money::round(Money::sub($disposal->proceeds_amount, $disposal->disposal_costs));
        $gainLoss = Money::round($disposal->gain_loss_amount);
        $date = $disposal->disposal_date?->format('Y-m-d') ?? now()->toDateString();
        $gainCode = $category->gl_disposal_gain_loss_code ?? '7500';

        $this->dispatcher->publishAndDispatch(new AssetDisposalReadyEvent(
            disposalId: (string) $disposal->id,
            assetId: (string) $asset->id,
            assetCost: Money::toFloat($gross),
            accumulatedDepreciation: Money::toFloat($accumulated),
            proceedsAmount: Money::toFloat($netProceeds),
            gainLossAmount: Money::toFloat($gainLoss),
            glAssetAccountCode: $category->gl_asset_account_code ?? '1600',
            glAccumulatedDepreciationCode: $category->gl_accumulated_depreciation_code ?? '1650',
            glDisposalGainLossCode: $gainCode,
            disposalDate: $date,
            tenantId: $tenantId,
            companyId: $this->companyOf($asset)
        ));

        $assetAccount = $this->account($tenantId, $category->gl_asset_account_code ?? '1600', 'Fixed assets', 'asset', 'debit');
        $accumulatedAccount = $this->account($tenantId, $category->gl_accumulated_depreciation_code ?? '1650', 'Accumulated depreciation', 'asset', 'credit');
        $proceedsAccount = $this->account($tenantId, $category->gl_disposal_proceeds_code ?? '1100', 'Asset disposal proceeds', 'asset', 'debit');
        $gainAccount = $this->account($tenantId, $gainCode, 'Gain on asset disposal', 'revenue', 'credit');
        $lossAccount = $category->gl_disposal_loss_code !== null
            ? $this->account($tenantId, (string) $category->gl_disposal_loss_code, 'Loss on asset disposal', 'expense', 'debit')
            : $gainAccount;

        $lines = [];
        if (Money::isPositive($accumulated)) {
            $lines[] = $this->line($accumulatedAccount, $accumulated, '0', "Dr accumulated depreciation ({$asset->asset_number})");
        }
        if (Money::isPositive($netProceeds)) {
            $lines[] = $this->line($proceedsAccount, $netProceeds, '0', "Dr disposal proceeds ({$asset->asset_number})");
        }
        if (Money::isNegative($gainLoss)) {
            $lines[] = $this->line($lossAccount, Money::round(Money::abs($gainLoss)), '0', "Dr loss on disposal ({$asset->asset_number})");
        }
        $lines[] = $this->line($assetAccount, '0', $gross, "Cr fixed asset ({$asset->asset_number})");
        if (Money::isPositive($gainLoss)) {
            $lines[] = $this->line($gainAccount, '0', $gainLoss, "Cr gain on disposal ({$asset->asset_number})");
        }
        if (Money::isNegative($netProceeds)) {
            $lines[] = $this->line($proceedsAccount, '0', Money::round(Money::abs($netProceeds)), "Cr disposal costs exceeding proceeds ({$asset->asset_number})");
        }

        $entry = $this->post($asset, $date, 'DSP', 'eam_asset_disposal', (string) $disposal->id, "Asset disposal ({$disposal->disposal_type}): {$asset->asset_number}", $lines, $actorUserId);
        $disposal->update(['journal_entry_id' => (string) $entry->id]);

        return $entry;
    }

    public function postImprovementEntry(MaintenanceWorkOrder $workOrder, FixedAsset $asset, string $amount, ?string $actorUserId = null): ?JournalEntry
    {
        $amount = Money::round($amount);
        if (! Money::isPositive($amount) || $workOrder->journal_entry_id !== null) {
            return null;
        }

        $tenantId = (string) $asset->tenant_id;
        $category = $this->categoryOf($asset);
        $date = $workOrder->completed_at?->format('Y-m-d') ?? now()->toDateString();

        $entry = $this->post($asset, $date, 'UPG', 'eam_maintenance_work_order', (string) $workOrder->id, "Capitalized improvement {$workOrder->work_order_number}: {$asset->asset_number}", [
            $this->line($this->account($tenantId, $category->gl_asset_account_code ?? '1600', 'Fixed assets', 'asset', 'debit'), $amount, '0', "Dr fixed asset ({$asset->asset_number})"),
            $this->line($this->account($tenantId, $category->gl_clearing_account_code ?? '2150', 'Fixed asset acquisition clearing', 'liability', 'credit'), '0', $amount, "Cr acquisition clearing ({$workOrder->work_order_number})"),
        ], $actorUserId);

        $workOrder->update(['journal_entry_id' => (string) $entry->id]);

        return $entry;
    }

    /**
     * Creates and posts the journal entry in the open period of the date.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function post(FixedAsset $asset, string $date, string $prefix, string $sourceType, string $sourceId, string $description, array $lines, ?string $actorUserId): JournalEntry
    {
        $tenantId = (string) $asset->tenant_id;

        return $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $this->companyOf($asset),
            'fiscal_period_id' => $this->periodFor($tenantId, $date)->id,
            'entry_number' => 'EAM-'.$prefix.'-'.strtoupper(Str::random(8)),
            'posting_date' => $date,
            'document_date' => $date,
            'source_document_type' => $sourceType,
            'source_document_id' => $sourceId,
            'journal_type' => 'standard',
            'description' => $description,
            'lines' => $lines,
        ], $actorUserId);
    }

    /**
     * The open fiscal period containing the date, as a 422 when there is none.
     */
    private function periodFor(string $tenantId, string $date): FiscalPeriod
    {
        try {
            return $this->periods->forDate($tenantId, $date, self::Subledger);
        } catch (FiscalPeriodException $exception) {
            throw new AssetOperationException($exception->getMessage(), 'fiscal_period');
        }
    }

    /**
     * A journal line with decimal string amounts.
     *
     * @return array<string, mixed>
     */
    private function line(ChartOfAccount $account, string $debit, string $credit, string $description): array
    {
        return ['account_id' => $account->id, 'debit_amount' => Money::round($debit), 'credit_amount' => Money::round($credit), 'description' => $description];
    }

    /**
     * Credit side of the capitalization: the cost account the source invoice line was booked to, else the clearing
     * account of the category.
     */
    private function capitalizationCreditAccount(FixedAsset $asset, AssetCategory $category): ChartOfAccount
    {
        if ($asset->capitalization_account_id !== null) {
            $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $asset->tenant_id)->whereKey($asset->capitalization_account_id)->first();
            if ($account !== null) {
                return $account;
            }
        }

        return $this->account((string) $asset->tenant_id, $category->gl_clearing_account_code ?? '2150', 'Fixed asset acquisition clearing', 'liability', 'credit');
    }

    /**
     * The ledger account with the code, created on first use like the other posting listeners do.
     */
    private function account(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $code],
            [
                'id' => (string) Str::uuid7(),
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'account_class' => $type,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'currency' => TenantAccountingCurrency::of($tenantId),
                'is_active' => true,
            ]
        );
    }

    /**
     * The category of the asset; every asset has one (foreign key, restrict on delete).
     */
    private function categoryOf(FixedAsset $asset): AssetCategory
    {
        return AssetCategory::withoutGlobalScopes()->where('tenant_id', $asset->tenant_id)->findOrFail($asset->category_id);
    }

    /**
     * The company of the asset, the tenant when unset.
     */
    private function companyOf(FixedAsset $asset): string
    {
        return (string) ($asset->company_id ?? $asset->tenant_id);
    }
}
