<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Invoicing\Models\SalesCommission;
use Modules\Invoicing\Models\SalesCommissionSetting;
use Modules\Invoicing\Models\SalesCommissionStatement;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesRepresentative;
use Modules\Invoicing\Models\SalesTarget;
use Modules\Invoicing\Models\SalesTeam;
use Modules\Payroll\Calculation\Dec;

/**
 * Commissions and targets on the invoices of an agent. The agent is entered on the sales order, so an invoice takes it from
 * there unless it carries its own, and a credit note from the invoice it corrects. Accrual reads each issued invoice once,
 * with the rate of the agent at that moment; a credit note gives a negative commission. Invoices in a currency other than
 * the accounting one are left out and counted, because a statement posts in the accounting currency. The accrual in the
 * ledger follows the payroll: no account is assumed, and a refused posting stays on the statement until it can go through.
 */
final class SalesCommissionService
{
    public const string SOURCE = 'sales_commission_statement';

    private const array CountedInvoiceTypes = ['standard', 'sdi_electronic', 'ch_qr_bill'];

    private const string CreditNoteType = 'credit_note';

    private const array VoidStatuses = ['cancelled', 'void'];

    /** Drafts of the invoicing module carry this placeholder until emission; invoices of the older flows stay in draft with their real number. */
    private const string DraftNumberPrefix = 'DRAFT-';

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
    ) {}

    /**
     * @return array{accrued: int, skipped_foreign_currency: int}
     */
    public function accrue(string $tenantId, string $from, string $to): array
    {
        $currency = TenantAccountingCurrency::of($tenantId);
        $representatives = SalesRepresentative::query()->get()->keyBy('id');
        $invoices = $this->issuedInvoices($from, $to)->get();
        $agents = $this->agentsOf($invoices);
        $existing = SalesCommission::query()->whereIn('invoice_id', $invoices->modelKeys())->pluck('invoice_id')->flip();
        $accrued = 0;
        $skipped = 0;

        foreach ($invoices as $invoice) {
            $representative = $representatives->get($agents[$invoice->id] ?? null);

            if ($representative === null || $existing->has($invoice->id)) {
                continue;
            }

            if ($invoice->currency !== $currency) {
                $skipped++;

                continue;
            }

            $base = self::signedNet($invoice);
            $rate = (string) $representative->default_commission_rate;

            SalesCommission::create([
                'sales_representative_id' => $representative->id,
                'invoice_id' => $invoice->id,
                'base_amount' => $base,
                'commission_rate' => $rate,
                'commission_amount' => Dec::round(Dec::div(Dec::mul($base, $rate), '100')),
                'status' => 'accrued',
            ]);
            $accrued++;
        }

        return ['accrued' => $accrued, 'skipped_foreign_currency' => $skipped];
    }

    /**
     * Gather the accrued commissions of the invoices of a period that no statement holds yet.
     */
    public function createStatement(string $tenantId, SalesRepresentative $representative, string $from, string $to): SalesCommissionStatement
    {
        return DB::transaction(function () use ($tenantId, $representative, $from, $to): SalesCommissionStatement {
            $invoiceIds = $this->issuedInvoices($from, $to)->pluck('id');
            $commissions = SalesCommission::query()
                ->where('sales_representative_id', $representative->id)
                ->whereIn('invoice_id', $invoiceIds)
                ->whereNull('commission_statement_id')
                ->where('status', 'accrued')
                ->lockForUpdate()
                ->get();

            if ($commissions->isEmpty()) {
                throw new InvalidArgumentException('Nessuna provvigione maturata da mettere nel conteggio del periodo.');
            }

            $statement = SalesCommissionStatement::create([
                'sales_representative_id' => $representative->id,
                'period_start' => $from,
                'period_end' => $to,
                'currency' => TenantAccountingCurrency::of($tenantId),
                'total_base' => Dec::round(Dec::sum($commissions->map(static fn (SalesCommission $commission): string => (string) $commission->base_amount))),
                'total_commission' => Dec::round(Dec::sum($commissions->map(static fn (SalesCommission $commission): string => (string) $commission->commission_amount))),
            ]);

            SalesCommission::query()->whereKey($commissions->modelKeys())->update(['commission_statement_id' => $statement->id]);

            return $statement->load('commissions');
        });
    }

    /**
     * Approve a statement and try to accrue it. A refusal of the ledger stays on the statement: the approval stands.
     */
    public function approve(SalesCommissionStatement $statement, User $actor): SalesCommissionStatement
    {
        if ($statement->status !== SalesCommissionStatement::StatusDraft) {
            throw new InvalidArgumentException('Si approva un conteggio in bozza.');
        }

        DB::transaction(function () use ($statement, $actor): void {
            $statement->update([
                'status' => SalesCommissionStatement::StatusApproved,
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now(),
                'ledger_status' => SalesCommissionStatement::LedgerPending,
            ]);
            SalesCommission::query()->where('commission_statement_id', $statement->id)->update(['status' => 'approved']);
        });

        try {
            $this->post($statement);
        } catch (InvalidArgumentException $e) {
            Log::warning('Commission statement waits for the ledger.', ['statement_id' => $statement->id, 'reason' => $e->getMessage()]);
            $statement->update(['ledger_error' => $e->getMessage()]);
        }

        return $statement;
    }

    /**
     * Post a statement that was waiting, for the API: here a refusal is thrown, so the caller reads the reason.
     */
    public function retryPosting(SalesCommissionStatement $statement): SalesCommissionStatement
    {
        if ($statement->ledger_status !== SalesCommissionStatement::LedgerPending) {
            throw new InvalidArgumentException('Il conteggio non è in attesa di registrazione.');
        }

        try {
            return $this->post($statement);
        } catch (InvalidArgumentException $e) {
            $statement->update(['ledger_error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function markPaid(SalesCommissionStatement $statement): SalesCommissionStatement
    {
        if ($statement->status !== SalesCommissionStatement::StatusApproved) {
            throw new InvalidArgumentException('Si paga un conteggio approvato.');
        }

        $now = CarbonImmutable::now();

        DB::transaction(function () use ($statement, $now): void {
            $statement->update(['status' => SalesCommissionStatement::StatusPaid, 'paid_at' => $now]);
            SalesCommission::query()->where('commission_statement_id', $statement->id)->update(['status' => 'paid', 'paid_at' => $now]);
        });

        return $statement;
    }

    /**
     * Targets against what was invoiced, for the targets touching a period.
     *
     * @return list<array<string, mixed>>
     */
    public function attainment(string $from, string $to): array
    {
        $targets = SalesTarget::query()->whereDate('period_end', '>=', $from)->whereDate('period_start', '<=', $to)->orderBy('period_start')->get();
        $teams = SalesTeam::query()->with('representatives')->get()->keyBy('id');
        $names = SalesRepresentative::query()->pluck('name', 'id');

        return $targets->map(function (SalesTarget $target) use ($teams, $names): array {
            $team = $target->sales_team_id === null ? null : $teams->get($target->sales_team_id);
            $representativeIds = $team?->representatives->modelKeys() ?? [$target->sales_representative_id];
            $actual = $this->netSales($representativeIds, $target->period_start->format('Y-m-d'), $target->period_end->format('Y-m-d'), (string) $target->currency);
            $goal = (string) $target->target_amount;

            return [
                'target_id' => $target->id,
                'owner_type' => $team === null ? 'agent' : 'team',
                'owner_id' => $team?->id ?? $target->sales_representative_id,
                'owner_name' => $team?->name ?? $names->get($target->sales_representative_id),
                'period_start' => $target->period_start->format('Y-m-d'),
                'period_end' => $target->period_end->format('Y-m-d'),
                'currency' => $target->currency,
                'target_amount' => $goal,
                'actual_amount' => Dec::round($actual),
                'attainment_percent' => Dec::isPositive($goal) ? Dec::round(Dec::div(Dec::mul($actual, '100'), $goal)) : null,
            ];
        })->all();
    }

    private function post(SalesCommissionStatement $statement): SalesCommissionStatement
    {
        $tenantId = (string) $statement->tenant_id;
        $settings = SalesCommissionSetting::query()->first();
        $expense = $settings?->expense_account_id ?? throw new InvalidArgumentException('Commission posting refused: configure the commission expense account.');
        $payable = $settings->payable_account_id ?? throw new InvalidArgumentException('Commission posting refused: configure the commissions payable account.');

        if (ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey([$expense, $payable])->count() !== 2) {
            throw new InvalidArgumentException('Commission posting refused: the accounts do not belong to this company.');
        }

        $amount = (string) $statement->total_commission;
        $isNegative = Dec::isNegative($amount);
        $value = Dec::round($isNegative ? Dec::neg($amount) : $amount);
        $date = $statement->period_end->format('Y-m-d');
        $period = $this->periods->forDate($tenantId, $date);
        $label = 'Commissions '.$statement->period_start->format('Y-m-d').' / '.$date;

        return DB::transaction(function () use ($statement, $tenantId, $expense, $payable, $value, $isNegative, $date, $period, $label): SalesCommissionStatement {
            $entry = $this->postingService->createAndPost([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'fiscal_period_id' => $period->id,
                'entry_number' => 'COMMISSION-'.strtoupper(Str::random(10)),
                'source_document_type' => self::SOURCE,
                'source_document_id' => (string) $statement->id,
                'document_number' => $label,
                'posting_date' => $date,
                'document_date' => $date,
                'journal_type' => 'standard',
                'description' => $label,
                'lines' => [
                    ['account_id' => $expense, 'debit_amount' => $isNegative ? '0' : $value, 'credit_amount' => $isNegative ? $value : '0', 'currency' => $statement->currency, 'description' => 'Commission expense'],
                    ['account_id' => $payable, 'debit_amount' => $isNegative ? $value : '0', 'credit_amount' => $isNegative ? '0' : $value, 'currency' => $statement->currency, 'description' => 'Commissions payable'],
                ],
            ]);

            $statement->update(['ledger_status' => SalesCommissionStatement::LedgerPosted, 'journal_entry_id' => $entry->id, 'ledger_error' => null]);

            return $statement;
        });
    }

    /**
     * @return Builder<SalesInvoice>
     */
    private function issuedInvoices(string $from, string $to): Builder
    {
        return SalesInvoice::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->whereIn('invoice_type', [...self::CountedInvoiceTypes, self::CreditNoteType])
            ->whereNotIn('status', self::VoidStatuses)
            ->where(static fn ($query) => $query->where('status', '!=', 'draft')->orWhere('invoice_number', 'not like', self::DraftNumberPrefix.'%'));
    }

    /**
     * The agent of each invoice, as a sales representative id. sales_rep_id on an order is not constrained to a table, so
     * it is read as a representative and then as the user of one.
     *
     * @param  Collection<int, SalesInvoice>  $invoices
     * @return array<string, string>
     */
    private function agentsOf(Collection $invoices): array
    {
        $originals = SalesInvoice::query()->whereIn('id', $invoices->pluck('related_invoice_id')->filter()->all())->get()->keyBy('id');
        $orderIds = $invoices->pluck('sales_order_id')->merge($originals->pluck('sales_order_id'))->filter()->unique()->all();
        $orderAgents = SalesOrder::query()->whereIn('id', $orderIds)->pluck('sales_rep_id', 'id');
        $representatives = SalesRepresentative::query()->get(['id', 'user_id']);
        $ids = $representatives->pluck('id', 'id');
        $byUser = $representatives->whereNotNull('user_id')->pluck('id', 'user_id');
        $declared = static fn (SalesInvoice $invoice): ?string => $invoice->sales_rep_id ?? $orderAgents->get($invoice->sales_order_id);
        $agents = [];

        foreach ($invoices as $invoice) {
            $original = $originals->get($invoice->related_invoice_id);
            $agent = $declared($invoice) ?? ($original === null ? null : $declared($original));
            $representativeId = $ids->get($agent) ?? $byUser->get($agent);

            if ($representativeId !== null) {
                $agents[(string) $invoice->id] = (string) $representativeId;
            }
        }

        return $agents;
    }

    /**
     * @param  array<int, string|null>  $representativeIds
     */
    private function netSales(array $representativeIds, string $from, string $to, string $currency): string
    {
        $invoices = $this->issuedInvoices($from, $to)->where('currency', $currency)->get();
        $agents = $this->agentsOf($invoices);
        $wanted = array_filter($representativeIds);

        return Dec::sum($invoices
            ->filter(static fn (SalesInvoice $invoice): bool => in_array($agents[$invoice->id] ?? null, $wanted, true))
            ->map(static fn (SalesInvoice $invoice): string => self::signedNet($invoice)));
    }

    private static function signedNet(SalesInvoice $invoice): string
    {
        $net = (string) $invoice->total_net;

        return Dec::round($invoice->invoice_type === self::CreditNoteType && ! Dec::isNegative($net) ? Dec::neg($net) : $net);
    }
}
