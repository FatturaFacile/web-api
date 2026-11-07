<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\EnasarcoParameter;
use Modules\GeneralLedger\Models\EnasarcoPrincipalStatement;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\SDI\Enums\TipoRitenuta;

/**
 * The tenant as a principal (mandante): on the commissions its agents invoice with an Enasarco block (RT04), it owes
 * the fund the agent's share it withheld plus its own share. The quarterly statement (distinta) is computed per agent
 * from the received invoices and the parameters of the year (rate, agent share, annual ceiling), which are entered by
 * the tenant with their source: nothing is assumed. The principal's share is posted once per agent and quarter,
 * Dr the principal contribution cost and Cr the contributions payable that already holds the agent's share.
 */
final readonly class EnasarcoPrincipalService
{
    public const string Source = 'enasarco_principal_statement';

    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private FiscalPeriodResolver $periods,
        private LedgerAccountResolver $accounts,
    ) {}

    /**
     * Computes and posts the statement of a quarter. Agents already in a statement of that quarter keep their line, so a
     * second run adds only the agents that were missing. The year is serialised per tenant, so two runs cannot post the
     * same line twice.
     *
     * @param  array<string, string>  $agentTypes  agent type by agent contact id, overriding the default
     * @return Collection<int, EnasarcoPrincipalStatement>
     *
     * @throws ValidationException when the parameters of the year are missing for an agent type
     */
    public function generate(string $tenantId, int $year, int $quarter, string $defaultAgentType, array $agentTypes = [], ?string $userId = null): Collection
    {
        return DB::transaction(function () use ($tenantId, $year, $quarter, $defaultAgentType, $agentTypes, $userId): Collection {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["enasarco-principal:{$tenantId}:{$year}"]);
            }

            [$from, $to] = $this->quarterBounds($year, $quarter);
            $existing = EnasarcoPrincipalStatement::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('year', $year)->where('quarter', $quarter)->pluck('contact_id')->all();
            $quarterRows = $this->commissions($tenantId, $from, $to);
            $previousRows = $quarter > 1 ? $this->commissions($tenantId, CarbonImmutable::create($year, 1, 1), $from->subDay()) : [];
            $missing = [];

            foreach ($quarterRows as $contactId => $row) {
                if (in_array($contactId, $existing, true)) {
                    continue;
                }

                $agentType = $agentTypes[$contactId] ?? $defaultAgentType;
                $parameter = EnasarcoParameter::resolve($tenantId, $year, $agentType);

                if ($parameter === null) {
                    $missing[$agentType] = __('sdi.fiscal.enasarco_parameters_missing', ['year' => $year]);

                    continue;
                }

                $this->createLine($tenantId, $year, $quarter, (string) $contactId, $agentType, $row, $previousRows[$contactId]['base'] ?? '0', $parameter, $to, $userId);
            }

            if ($missing !== []) {
                throw ValidationException::withMessages(['agent_type' => array_values($missing)]);
            }

            return $this->statement($tenantId, $year, $quarter);
        });
    }

    /**
     * @return Collection<int, EnasarcoPrincipalStatement>
     */
    public function statement(string $tenantId, int $year, int $quarter): Collection
    {
        return EnasarcoPrincipalStatement::withoutGlobalScopes()
            ->with('agent:id,company_name,first_name,last_name')
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->where('quarter', $quarter)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  array{base: string, withheld: string, documents: int}  $row
     */
    private function createLine(string $tenantId, int $year, int $quarter, string $contactId, string $agentType, array $row, string $subjectBefore, EnasarcoParameter $parameter, CarbonImmutable $to, ?string $userId): EnasarcoPrincipalStatement
    {
        $room = Decimal::sub((string) $parameter->annual_ceiling, $subjectBefore);
        $room = Decimal::isNegative($room) ? '0' : $room;
        $subject = Decimal::compare($row['base'], $room) > 0 ? $room : $row['base'];
        $principalRate = Decimal::div(Decimal::mul((string) $parameter->rate_percent, Decimal::sub('100', (string) $parameter->agent_share_percent)), '100');
        $principalAmount = Decimal::round2(Decimal::div(Decimal::mul($subject, $principalRate), '100'));

        $line = EnasarcoPrincipalStatement::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'year' => $year,
            'quarter' => $quarter,
            'contact_id' => $contactId,
            'agent_type' => $agentType,
            'documents' => $row['documents'],
            'commissions' => Decimal::round2($row['base']),
            'subject_before' => Decimal::round2(Decimal::compare($subjectBefore, (string) $parameter->annual_ceiling) > 0 ? (string) $parameter->annual_ceiling : $subjectBefore),
            'subject_base' => Decimal::round2($subject),
            'annual_ceiling' => (string) $parameter->annual_ceiling,
            'rate_percent' => (string) $parameter->rate_percent,
            'agent_share_percent' => (string) $parameter->agent_share_percent,
            'agent_withheld' => Decimal::round2($row['withheld']),
            'principal_amount' => $principalAmount,
            'total_due' => Decimal::round2(Decimal::add($row['withheld'], $principalAmount)),
            'created_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
        ]);

        if (Decimal::isPositive($principalAmount)) {
            $line->update(['journal_entry_id' => $this->post($line, $to)]);
        }

        return $line;
    }

    /**
     * The principal's share: a cost of the quarter and a debt towards the fund.
     */
    private function post(EnasarcoPrincipalStatement $line, CarbonImmutable $postingDate): string
    {
        $tenantId = (string) $line->tenant_id;
        $date = $postingDate->toDateString();
        $period = $this->periods->forDate($tenantId, $date);
        $amount = (string) $line->principal_amount;
        $description = "Enasarco principal share Q{$line->quarter}/{$line->year}";

        $entry = $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'ENS-'.strtoupper(Str::random(10)),
            'source_document_type' => self::Source,
            'source_document_id' => (string) $line->id,
            'document_number' => "ENASARCO-{$line->year}-Q{$line->quarter}",
            'posting_date' => $date,
            'document_date' => $date,
            'journal_type' => 'standard',
            'description' => $description,
            'lines' => [
                ['account_id' => $this->accounts->enasarcoPrincipalExpense($tenantId)->id, 'debit_amount' => $amount, 'credit_amount' => '0', 'description' => $description],
                ['account_id' => $this->accounts->contributionsPayable($tenantId)->id, 'debit_amount' => '0', 'credit_amount' => $amount, 'description' => $description],
            ],
        ]);

        return (string) $entry->id;
    }

    /**
     * Commissions of the received invoices with an Enasarco block, per agent: the taxable amount and the agent's share
     * withheld. A credit note (TD04) of the agent takes both back.
     *
     * @return array<string, array{base: string, withheld: string, documents: int}>
     */
    private function commissions(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];

        $invoices = PassiveInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('withholding_tax_amount', '>', 0)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->get(['id', 'supplier_id', 'document_type', 'total_net', 'withholdings']);

        foreach ($invoices as $invoice) {
            $withheld = '0';

            foreach ($invoice->withholdings ?? [] as $withholding) {
                if (($withholding['type'] ?? null) === TipoRitenuta::Rt04->value) {
                    $withheld = Decimal::add($withheld, (string) $withholding['amount']);
                }
            }

            if (! Decimal::isPositive($withheld)) {
                continue;
            }

            $sign = $invoice->document_type === 'TD04' ? '-1' : '1';
            $row = $rows[(string) $invoice->supplier_id] ?? ['base' => '0', 'withheld' => '0', 'documents' => 0];
            $rows[(string) $invoice->supplier_id] = [
                'base' => Decimal::add($row['base'], Decimal::mul(Decimal::abs((string) ($invoice->total_net ?? '0')), $sign)),
                'withheld' => Decimal::add($row['withheld'], Decimal::mul(Decimal::abs($withheld), $sign)),
                'documents' => $row['documents'] + 1,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function quarterBounds(int $year, int $quarter): array
    {
        $from = CarbonImmutable::create($year, (($quarter - 1) * 3) + 1, 1);

        return [$from, $from->addMonthsNoOverflow(2)->endOfMonth()->startOfDay()];
    }
}
