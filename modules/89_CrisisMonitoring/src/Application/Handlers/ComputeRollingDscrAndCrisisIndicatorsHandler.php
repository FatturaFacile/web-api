<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\CrisisMonitoring\Application\Commands\ComputeRollingDscrAndCrisisIndicatorsCommand;
use Modules\CrisisMonitoring\Application\Services\CrisisEngine;
use Modules\CrisisMonitoring\Models\AdequateOrganizationalAssetReportCcii;
use Modules\CrisisMonitoring\Models\DscrMonthlyProjection;
use Modules\CrisisMonitoring\Models\SwissCo725SolvencyAssessment;

/**
 * Evaluates the company and stores the day's snapshot: the twelve monthly DSCR rows and the Italian and Swiss assessments. A second run of
 * the same day replaces the first. The stored rows feed the history; the dashboard itself is always computed from the live records.
 */
final class ComputeRollingDscrAndCrisisIndicatorsHandler
{
    public function __construct(private readonly CrisisEngine $engine) {}

    /**
     * @return array<string, mixed> the dashboard snapshot that was stored
     */
    public function handle(ComputeRollingDscrAndCrisisIndicatorsCommand $command): array
    {
        $inputs = $this->engine->load($command->tenant, $command->asOf);
        $snapshot = $this->engine->evaluate($inputs);
        $date = (string) $snapshot['as_of'];
        $trigger = $command->trigger === 'scheduled' ? 'scheduled' : 'manual';

        DB::transaction(function () use ($snapshot, $date, $trigger): void {
            DscrMonthlyProjection::query()->whereDate('snapshot_date', $date)->forceDelete();

            foreach ($snapshot['timeline'] as $row) {
                DscrMonthlyProjection::query()->create([
                    'snapshot_date' => $date,
                    'month' => $row['month'],
                    'currency' => $snapshot['currency'],
                    'operating_inflows' => $row['operating_inflows'],
                    'operating_outflows' => $row['operating_outflows'],
                    'debt_service' => $row['debt_service'],
                    'tax_social' => $row['tax_social'],
                    'capex' => $row['capex'],
                    'free_cash_flow' => $row['free_cash_flow'],
                    'closing_cash' => $row['closing_cash'],
                    'cumulative_cash_with_credit' => $row['cumulative_cash_with_credit'],
                    'dscr_treasury' => $row['dscr_treasury'],
                    'dscr_operating' => $row['dscr_operating'],
                ]);
            }

            $dscr = $snapshot['dscr'];
            AdequateOrganizationalAssetReportCcii::query()->whereDate('assessment_date', $date)->forceDelete();
            AdequateOrganizationalAssetReportCcii::query()->create([
                'assessment_date' => $date,
                'trigger' => $trigger,
                'overall_status' => $snapshot['ccii']['overall_status'],
                'dscr_6m_treasury' => $dscr['treasury']['m6']['value'],
                'dscr_12m_treasury' => $dscr['treasury']['m12']['value'],
                'dscr_6m_operating' => $dscr['operating']['m6']['value'],
                'dscr_12m_operating' => $dscr['operating']['m12']['value'],
                'indicators' => $snapshot['ccii']['indicators'],
                'public_creditors' => $snapshot['ccii']['public_creditors'],
                'cnc_test' => $snapshot['ccii']['cnc'],
            ]);

            $swiss = $snapshot['swiss'];
            SwissCo725SolvencyAssessment::query()->whereDate('assessment_date', $date)->forceDelete();
            SwissCo725SolvencyAssessment::query()->create([
                'assessment_date' => $date,
                'trigger' => $trigger,
                'overall_status' => $swiss['overall_status'],
                'art725_status' => $swiss['art725']['status'],
                'art725a_status' => $swiss['art725a']['status'],
                'art725b_status' => $swiss['art725b']['status'],
                'min_cumulative_cash' => $swiss['art725']['min_cumulative_cash'],
                'first_negative_month' => $swiss['art725']['first_negative_month'],
                'net_assets' => $swiss['art725a']['net_assets'],
                'capital_coverage_ratio' => $swiss['art725a']['coverage_ratio'],
                'net_going_concern' => $swiss['art725b']['going_concern']['net'],
                'net_liquidation' => $swiss['art725b']['liquidation']['net'],
                'required_subordination' => $swiss['subordination']['required_claim_amount'],
                'court_notification_required' => $swiss['art725b']['court_notification_required'],
                'details' => ['art725' => $swiss['art725'], 'art725a' => $swiss['art725a'], 'art725b' => $swiss['art725b'], 'subordination' => $swiss['subordination']],
            ]);
        });

        return $snapshot;
    }
}
