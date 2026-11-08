<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgAccountingSetting;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Support\Money;

/**
 * Posts production to the General Ledger through its double-entry posting service, in the open fiscal period that
 * contains the document date (periods locked for the MFG subledger are refused, none is ever created):
 *
 * - material issue: Dr WIP, Cr raw materials, at the inventory valuation of the issued quantity;
 * - operation confirmation: Dr WIP, Cr labour absorbed and overhead absorbed, at the work centre rates;
 * - subcontracted operation received: Dr WIP, Cr subcontracting accrual (GR/IR), at the agreed unit cost;
 * - output receipt: Dr finished goods, Cr WIP, at the standard unit cost of the order;
 * - close: the WIP balance left goes to the material, labour, overhead and subcontracting variance accounts.
 *
 * Accounts come from mfg_accounting_settings, else the defaults below (created on first use like the other posting
 * services do).
 */
final readonly class ManufacturingAccountingService
{
    /** Subledger code of manufacturing for period locks. */
    public const string Subledger = 'MFG';

    /**
     * Default account of each role: code, name, type, normal balance. Raw materials and finished goods use the
     * inventory account the inventory and procurement postings use; GR/IR is the procurement clearing account.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const array DefaultAccounts = [
        'raw_material' => ['1200', 'Merchandise Inventory Asset', 'asset', 'debit'],
        'wip' => ['1210', 'Work in progress (production)', 'asset', 'debit'],
        'finished_goods' => ['1200', 'Merchandise Inventory Asset', 'asset', 'debit'],
        'labor_absorbed' => ['5810', 'Production labour absorbed', 'expense', 'credit'],
        'overhead_absorbed' => ['5820', 'Production overhead absorbed', 'expense', 'credit'],
        'subcontract_accrual' => ['2010', 'GR/IR Clearing Account', 'liability', 'credit'],
        'material_variance' => ['5910', 'Production material variance', 'expense', 'debit'],
        'labor_variance' => ['5920', 'Production labour variance', 'expense', 'debit'],
        'overhead_variance' => ['5930', 'Production overhead variance', 'expense', 'debit'],
        'subcontract_variance' => ['5940', 'Production subcontracting variance', 'expense', 'debit'],
    ];

    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * Dr WIP / Cr raw materials for components issued (or backflushed, or consumed at a subcontractor).
     */
    public function postMaterialIssue(MfgProductionOrder $order, string $amount, string $date, string $sourceType, string $sourceId, string $description, ?string $actorUserId): ?JournalEntry
    {
        return $this->postPair($order, 'wip', 'raw_material', $amount, $date, 'ISS', $sourceType, $sourceId, $description, $actorUserId);
    }

    /**
     * Dr WIP / Cr labour absorbed and overhead absorbed for a confirmed operation.
     */
    public function postConversion(MfgProductionOrder $order, string $laborCost, string $overheadCost, string $date, string $sourceId, string $description, ?string $actorUserId): ?JournalEntry
    {
        $labor = Money::round($laborCost);
        $overhead = Money::round($overheadCost);
        $total = Money::round(Money::add($labor, $overhead));
        if (! Money::isPositive($total)) {
            return null;
        }

        $tenantId = (string) $order->tenant_id;
        $lines = [$this->line($this->account($tenantId, 'wip'), $total, '0', "Dr WIP ({$order->order_number})")];
        if (Money::isPositive($labor)) {
            $lines[] = $this->line($this->account($tenantId, 'labor_absorbed'), '0', $labor, "Cr labour absorbed ({$order->order_number})");
        }
        if (Money::isPositive($overhead)) {
            $lines[] = $this->line($this->account($tenantId, 'overhead_absorbed'), '0', $overhead, "Cr overhead absorbed ({$order->order_number})");
        }

        return $this->post($order, $date, 'CNF', 'mfg_operation_confirmation', $sourceId, $description, $lines, $actorUserId);
    }

    /**
     * Dr WIP / Cr subcontracting accrual for the processing received from a subcontractor.
     */
    public function postSubcontractService(MfgProductionOrder $order, string $amount, string $date, string $sourceId, string $description, ?string $actorUserId): ?JournalEntry
    {
        return $this->postPair($order, 'wip', 'subcontract_accrual', $amount, $date, 'SUB', 'mfg_subcontract_order', $sourceId, $description, $actorUserId);
    }

    /**
     * Dr finished goods / Cr WIP for output received at standard cost.
     */
    public function postOutputReceipt(MfgProductionOrder $order, string $amount, string $date, string $sourceId, string $description, ?string $actorUserId): ?JournalEntry
    {
        return $this->postPair($order, 'finished_goods', 'wip', $amount, $date, 'RCP', 'mfg_material_movement', $sourceId, $description, $actorUserId);
    }

    /**
     * Clears the WIP balance of a closed order to the variance accounts: positive variances (actual above standard)
     * are debited, negative ones credited, WIP takes the opposite side.
     *
     * @param  array<string, string>  $variances  cost element (material, labor, overhead, subcontract) => variance
     */
    public function postVariances(MfgProductionOrder $order, array $variances, string $date, ?string $actorUserId): ?JournalEntry
    {
        $tenantId = (string) $order->tenant_id;
        $wip = $this->account($tenantId, 'wip');
        $lines = [];
        $net = '0';

        foreach ($variances as $element => $variance) {
            $amount = Money::round($variance);
            if (Money::isZero($amount)) {
                continue;
            }

            $account = $this->account($tenantId, $element.'_variance');
            $lines[] = Money::isPositive($amount)
                ? $this->line($account, $amount, '0', "Dr {$element} variance ({$order->order_number})")
                : $this->line($account, '0', Money::round(Money::abs($amount)), "Cr {$element} variance ({$order->order_number})");
            $net = Money::add($net, $amount);
        }

        if ($lines === []) {
            return null;
        }

        $net = Money::round($net);
        if (Money::isPositive($net)) {
            $lines[] = $this->line($wip, '0', $net, "Cr WIP settlement ({$order->order_number})");
        } elseif (Money::isNegative($net)) {
            $lines[] = $this->line($wip, Money::round(Money::abs($net)), '0', "Dr WIP settlement ({$order->order_number})");
        }

        return $this->post($order, $date, 'VAR', 'mfg_production_order', (string) $order->id, "Production variances: {$order->order_number}", $lines, $actorUserId);
    }

    /**
     * The open fiscal period of the date for manufacturing postings, as a 422 when there is none or it is locked.
     */
    public function assertPeriodOpen(string $tenantId, string $date): FiscalPeriod
    {
        try {
            return $this->periods->forDate($tenantId, $date, self::Subledger);
        } catch (FiscalPeriodException $exception) {
            throw new ManufacturingOperationException($exception->getMessage(), 'posting_date');
        }
    }

    /**
     * The account codes in force for the tenant, by role.
     *
     * @return array<string, string>
     */
    public function accountCodes(string $tenantId): array
    {
        $settings = MfgAccountingSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->first();
        $codes = [];

        foreach (self::DefaultAccounts as $role => [$code]) {
            $configured = $settings?->getAttribute($role.'_account_code');
            $codes[$role] = is_string($configured) && $configured !== '' ? $configured : $code;
        }

        return $codes;
    }

    /**
     * A two-line entry: debit one role, credit the other.
     */
    private function postPair(MfgProductionOrder $order, string $debitRole, string $creditRole, string $amount, string $date, string $prefix, string $sourceType, string $sourceId, string $description, ?string $actorUserId): ?JournalEntry
    {
        $amount = Money::round($amount);
        if (! Money::isPositive($amount)) {
            return null;
        }

        $tenantId = (string) $order->tenant_id;

        return $this->post($order, $date, $prefix, $sourceType, $sourceId, $description, [
            $this->line($this->account($tenantId, $debitRole), $amount, '0', "Dr {$debitRole} ({$order->order_number})"),
            $this->line($this->account($tenantId, $creditRole), '0', $amount, "Cr {$creditRole} ({$order->order_number})"),
        ], $actorUserId);
    }

    /**
     * Creates and posts the journal entry in the open period of the document date.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function post(MfgProductionOrder $order, string $date, string $prefix, string $sourceType, string $sourceId, string $description, array $lines, ?string $actorUserId): JournalEntry
    {
        $tenantId = (string) $order->tenant_id;

        return $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'fiscal_period_id' => $this->assertPeriodOpen($tenantId, $date)->id,
            'entry_number' => 'MFG-'.$prefix.'-'.strtoupper(Str::random(8)),
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
     * A journal line with decimal string amounts.
     *
     * @return array<string, mixed>
     */
    private function line(ChartOfAccount $account, string $debit, string $credit, string $description): array
    {
        return ['account_id' => $account->id, 'debit_amount' => Money::round($debit), 'credit_amount' => Money::round($credit), 'description' => $description];
    }

    /**
     * The ledger account of a role for the tenant, created on first use.
     */
    private function account(string $tenantId, string $role): ChartOfAccount
    {
        [, $name, $type, $normalBalance] = self::DefaultAccounts[$role];
        $code = $this->accountCodes($tenantId)[$role];

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
}
