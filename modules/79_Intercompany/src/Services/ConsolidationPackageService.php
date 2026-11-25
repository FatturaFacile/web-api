<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationService;
use Modules\GeneralLedger\Services\Consolidation\IntercompanyReconciliationService;
use Modules\Intercompany\Enums\Standard;
use Modules\Intercompany\Models\ConsolidationPackage;
use Modules\Intercompany\Support\GroupScope;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * The consolidated financial statement package of a run (fascicolo di bilancio consolidato): balance sheet, income statement
 * and cash flow in the chosen standard, the schedule of shareholdings and scope, the schedule of non-controlling interests,
 * the intercompany reconciliation and the elimination audit schedule, with the translation rates of the run. It is stored as
 * a snapshot with a checksum, so what was handed to the auditor can be proved later; JSON, CSV and XLSX downloads are
 * rendered from the stored payload. Column headings of the files are the field codes, the labels belong to the client.
 */
final readonly class ConsolidationPackageService
{
    public function __construct(
        private GroupScope $scope,
        private ConsolidationService $consolidation,
        private StatementsService $statements,
        private ConsolidationSchedulesService $schedules,
        private EliminationLedgerService $ledger,
        private IntercompanyReconciliationService $reconciliation,
    ) {}

    public function generate(User $user, ConsolidationRun $run, Standard $standard): ConsolidationPackage
    {
        $this->consolidation->assertCanReadRun($user, $run);
        $group = $this->scope->groupOfRun($run);
        $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->findOrFail($run->fiscal_period_id);
        $statements = $this->statements->statements($user, $run, $standard);

        $payload = [
            'meta' => ['standard' => $standard->value, 'run_id' => (string) $run->id, 'run_version' => (int) $run->version, 'currency' => (string) $run->group_currency, 'period_start' => $run->period_start->toDateString(), 'period_end' => $run->period_end->toDateString(), 'status' => $run->status, 'group_id' => (string) $group->id],
            'balance_sheet' => $statements['balance_sheet'],
            'income_statement' => $statements['income_statement'],
            'cash_flow' => $statements['cash_flow'],
            'presentation' => $statements['presentation'],
            'shareholdings' => $this->schedules->shareholdings($user, $run),
            'nci_schedule' => $this->schedules->nci($user, $run),
            'reciprocal_reconciliation' => $this->reconciliation->report($user, $group, $period),
            'elimination_ledger' => $this->ledger->ledger($user, $run),
            'translation' => ['translation_reserve' => $statements['translation_reserve'], 'rates' => $run->rates],
        ];

        return $this->scope->runAs((string) $run->tenant_id, fn (): ConsolidationPackage => DB::transaction(fn (): ConsolidationPackage => ConsolidationPackage::withoutGlobalScopes()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->id,
            'standard' => $standard->value,
            'payload' => $payload,
            'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'generated_at' => now(),
        ])));
    }

    /**
     * @return array<string, list<list<float|int|string|null>>> sheet name => rows (the first row is the heading)
     */
    public function tables(ConsolidationPackage $package): array
    {
        $payload = $package->payload;
        $tables = [];

        foreach (['balance_sheet', 'income_statement'] as $statement) {
            $rows = [['section', 'account_code', 'account_name', 'standalone', 'eliminations', 'consolidated']];

            foreach ($payload[$statement]['sections'] as $section) {
                foreach ($section['lines'] as $line) {
                    $rows[] = [$section['code'], $line['account_code'], $line['account_name'], $line['standalone'], $line['eliminations'], $line['consolidated']];
                }

                $rows[] = [$section['code'], 'TOTAL', '', $section['total']['standalone'], $section['total']['eliminations'], $section['total']['consolidated']];
            }

            $tables[$statement] = $rows;
        }

        $rows = [['code', 'section', 'amount']];

        foreach ($payload['cash_flow']['lines'] as $line) {
            $rows[] = [$line['code'], $line['section'], $line['amount']];
        }

        foreach (['operating', 'investing', 'financing', 'fx_effect', 'net_change', 'opening_cash', 'closing_cash', 'unreconciled'] as $key) {
            $rows[] = [$key, 'total', $payload['cash_flow'][$key]];
        }

        $tables['cash_flow'] = $rows;

        $rows = [['member_tenant_id', 'name', 'holder_tenant_id', 'direct_percentage', 'effective_percentage', 'voting_percentage', 'consolidation_method', 'consolidation_share', 'currency']];

        foreach ($payload['shareholdings']['rows'] as $row) {
            $rows[] = [$row['member_tenant_id'], $row['name'], $row['holder_tenant_id'], $row['direct_percentage'], $row['effective_percentage'], $row['voting_percentage'], $row['consolidation_method'], $row['consolidation_share'], $row['currency']];
        }

        $tables['shareholdings'] = $rows;

        $rows = [['member_tenant_id', 'name', 'nci_percentage', 'nci_opening', 'nci_profit', 'nci_net_assets']];

        foreach ($payload['nci_schedule']['rows'] as $row) {
            $rows[] = [$row['member_tenant_id'], $row['name'], $row['nci_percentage'], $row['nci_opening'], $row['nci_profit'], $row['nci_net_assets']];
        }

        $tables['nci_schedule'] = $rows;

        $rows = [['mapping_type', 'entity_a', 'account_a', 'amount_a', 'entity_b', 'account_b', 'amount_b', 'difference', 'status']];

        foreach ($payload['reciprocal_reconciliation']['rows'] as $row) {
            $rows[] = [$row['mapping_type'], $row['entity_a']['name'], $row['entity_a']['account_code'], $row['entity_a']['group_amount'], $row['entity_b']['name'], $row['entity_b']['account_code'], $row['entity_b']['group_amount'], $row['difference'], $row['status']];
        }

        $tables['reciprocal_reconciliation'] = $rows;

        $rows = [['elimination_type', 'description', 'reference', 'line_number', 'entity_tenant_id', 'account_code', 'debit', 'credit', 'pre_elimination_amount', 'post_elimination_amount']];

        foreach ($payload['elimination_ledger']['entries'] as $entry) {
            foreach ($entry['lines'] as $line) {
                $rows[] = [$entry['elimination_type'], $entry['description'], $entry['reference'], $line['line_number'], $line['entity_tenant_id'], $line['account_code'], $line['debit'], $line['credit'], $line['pre_elimination_amount'], $line['post_elimination_amount']];
            }
        }

        $tables['elimination_ledger'] = $rows;

        return $tables;
    }

    public function toCsv(ConsolidationPackage $package): string
    {
        $stream = fopen('php://temp', 'r+');

        foreach ($this->tables($package) as $name => $rows) {
            fputcsv($stream, ['#'.$name], ';', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($stream, $row, ';', '"', '\\');
            }

            fputcsv($stream, [], ';', '"', '\\');
        }

        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * Writes the XLSX of the package to a temporary file and returns its path; the caller deletes it after sending.
     */
    public function toXlsx(ConsolidationPackage $package): string
    {
        $path = tempnam(sys_get_temp_dir(), 'icpkg');

        if ($path === false) {
            throw new RuntimeException('A temporary file could not be created.');
        }

        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;

        foreach ($this->tables($package) as $name => $rows) {
            if ($first) {
                $writer->getCurrentSheet()->setName($name);
                $first = false;
            } else {
                $writer->addNewSheetAndMakeItCurrent()->setName($name);
            }

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return $path;
    }
}
