<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Exports;

use App\Services\Security\CsvFormulaNeutralizer;
use Modules\AgentCommissions\Domain\Enums\EnasarcoMandate;
use Modules\AgentCommissions\Domain\Enums\StatementStatus;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\Statement;
use Modules\AgentCommissions\Services\FiscalEngine;
use Modules\Payroll\Calculation\Dec;

/**
 * Structured exports for the compliance filings of the agents, as semicolon separated CSV (UTF-8 with BOM, so Excel
 * opens it): the Certificazione Unica (CU) summary and the Enasarco quarterly payment trace.
 *
 * CU follows the cash principle: only statements that were paid in the year count. The Enasarco trace follows the
 * quarter of the period of every approved, invoiced or paid statement. The CU causal of an agent is Q for a
 * single-mandate and R for a multi-mandate agent, as in the instructions of the form.
 */
final class ComplianceExportService
{
    public function __construct(private readonly FiscalEngine $engine) {}

    public function cuCsv(int $year, string $locale): string
    {
        $statements = Statement::query()
            ->where('status', StatementStatus::Paid->value)
            ->whereYear('paid_at', $year)
            ->get();
        $profiles = AgentProfile::query()->with('representative')->whereIn('id', $statements->pluck('agent_profile_id')->unique()->all())->get()->keyBy('id');
        $t = static fn (string $key): string => __('agent_commissions.export.cu.'.$key, [], $locale);
        $rows = [[$t('year'), $t('agent'), $t('tax_code'), $t('vat_number'), $t('causal'), $t('gross'), $t('withholding_base'), $t('withholding'), $t('enasarco_agent'), $t('statements')]];

        foreach ($statements->groupBy('agent_profile_id') as $profileId => $group) {
            $profile = $profiles->get($profileId);

            if ($profile === null) {
                continue;
            }

            $rows[] = [
                (string) $year,
                (string) $profile->representative?->name,
                (string) $profile->tax_code,
                (string) $profile->vat_number,
                $this->engine->mandateOf($profile) === EnasarcoMandate::Single->value ? 'Q' : 'R',
                $this->sum($group, 'gross_commission'),
                $this->sum($group, 'withholding_base'),
                $this->sum($group, 'withholding_amount'),
                $this->sum($group, 'enasarco_agent'),
                $group->pluck('number')->implode(' '),
            ];
        }

        return $this->csv($rows);
    }

    public function enasarcoCsv(int $year, ?int $quarter, string $locale): string
    {
        $statements = Statement::query()
            ->whereIn('status', [StatementStatus::Approved->value, StatementStatus::Invoiced->value, StatementStatus::Paid->value])
            ->whereYear('period_end', $year)
            ->get()
            ->filter(static fn (Statement $statement): bool => $quarter === null || (int) ceil($statement->period_end->month / 3) === $quarter);
        $profiles = AgentProfile::query()->with('representative')->whereIn('id', $statements->pluck('agent_profile_id')->unique()->all())->get()->keyBy('id');
        $t = static fn (string $key): string => __('agent_commissions.export.enasarco.'.$key, [], $locale);
        $rows = [[$t('year'), $t('quarter'), $t('registration'), $t('agent'), $t('tax_code'), $t('mandate'), $t('base'), $t('agent_contribution'), $t('company_contribution'), $t('firr'), $t('statements')]];

        $grouped = $statements->groupBy(static fn (Statement $statement): string => $statement->agent_profile_id.'|'.(int) ceil($statement->period_end->month / 3));

        foreach ($grouped->sortKeys() as $key => $group) {
            [$profileId, $period] = explode('|', (string) $key);
            $profile = $profiles->get($profileId);

            if ($profile === null || ! $this->engine->isItalianRegime($profile)) {
                continue;
            }

            $rows[] = [
                (string) $year,
                (string) $period,
                (string) $profile->enasarco_registration_number,
                (string) $profile->representative?->name,
                (string) $profile->tax_code,
                $this->engine->mandateOf($profile),
                $this->sum($group, 'enasarco_base'),
                $this->sum($group, 'enasarco_agent'),
                $this->sum($group, 'enasarco_company'),
                $this->sum($group, 'firr_accrual'),
                $group->pluck('number')->implode(' '),
            ];
        }

        return $this->csv($rows);
    }

    /**
     * @param  iterable<Statement>  $group
     */
    private function sum(iterable $group, string $field): string
    {
        $values = [];

        foreach ($group as $statement) {
            $values[] = (string) $statement->{$field};
        }

        return Dec::round(Dec::sum($values));
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, CsvFormulaNeutralizer::row($row), ';', '"', '\\');
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }
}
