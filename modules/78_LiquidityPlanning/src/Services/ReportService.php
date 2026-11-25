<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\LiquidityPlanning\Support\Levers;
use Modules\LiquidityPlanning\Support\ScenarioPresets;

/**
 * The board and bank report: the overview of the forecast and the sections aligned with the EBA Guidelines on loan
 * origination and monitoring (cash flow sustainability, debt service capacity, capital adequacy, leverage, stress resilience
 * and governance oversight), each with a status and a translated summary the client prints.
 */
final readonly class ReportService
{
    public function __construct(private ForecastService $forecast) {}

    /**
     * @param  array<string, mixed>|null  $scenarioInfo
     * @return array<string, mixed>
     */
    public function build(Tenant $tenant, ?Levers $levers, ?CarbonImmutable $asOf = null, ?array $scenarioInfo = null): array
    {
        $overview = $this->forecast->overview($tenant, $levers, $asOf, $scenarioInfo);
        $stress = $this->forecast->core($tenant, ScenarioPresets::stress(), $asOf);
        $baseline = $overview['indicators']['baseline'];
        $stressed = $stress['scenarioIndicators'] ?? $stress['baselineIndicators'];
        $stressHorizon = ($stress['scenarioHorizons'] ?: $stress['baselineHorizons'])[3];
        $currency = (string) $overview['currency'];
        $dscr = $baseline['dscr'];
        $capital = $baseline['capital'];
        $pfn = $baseline['pfn'];
        $liquidity = $baseline['liquidity'];
        $horizon180 = $overview['horizons'][3]['baseline'];

        $sections = [
            $this->section('cash_flow_sustainability', match ($liquidity['status']) {
                'negative' => 'critical', 'watch' => 'watch', default => 'ok',
            }, 'lpApi.eba.cash_flow.'.$liquidity['status'], ['closing' => $horizon180['closing_balance'], 'min' => $horizon180['min_balance'], 'min_date' => $horizon180['min_date'], 'date' => $liquidity['first_negative_date'] ?? '-', 'currency' => $currency]),
            $this->section('debt_service_capacity', match ($dscr['status']) {
                'critical' => 'critical', 'warning' => 'watch', default => 'ok',
            }, 'lpApi.eba.dscr.'.$dscr['status'], ['value' => $dscr['value'] ?? '-', 'operating' => $dscr['operating_cash_flow'], 'service' => $dscr['debt_service']['total'], 'currency' => $currency]),
            $this->section('capital_adequacy', match ($capital['status']) {
                'ok' => 'ok', 'watch' => 'watch', default => 'critical',
            }, 'lpApi.eba.capital.'.($capital['configured'] ? $capital['status'] : 'not_configured'), ['equity' => $capital['net_equity'], 'capital' => $capital['share_capital'] ?? '-', 'coverage' => $capital['coverage_percent'] ?? '-', 'regime' => $capital['regime'], 'currency' => $currency]),
            $this->section('leverage', match ($pfn['status']) {
                'breach' => 'critical', 'watch' => 'watch', default => 'ok',
            }, 'lpApi.eba.leverage.'.$pfn['status'], ['pfn' => $pfn['pfn'], 'ebitda' => $pfn['ebitda'], 'ratio' => $pfn['pfn_ebitda'] ?? '-', 'currency' => $currency]),
            $this->section('stress_resilience', Decimal::isNegative((string) $stressHorizon['min_balance']) ? 'critical' : ($stressed['dscr']['status'] === 'critical' ? 'watch' : 'ok'), 'lpApi.eba.stress.summary', ['closing' => $stressHorizon['closing_balance'], 'min' => $stressHorizon['min_balance'], 'dscr' => $stressed['dscr']['value'] ?? '-', 'currency' => $currency]),
            $this->section('governance_oversight', $overview['alerts'] === [] ? 'ok' : (in_array('critical', array_column($overview['alerts'], 'severity'), true) ? 'critical' : 'watch'), 'lpApi.eba.governance.summary', ['alerts' => count($overview['alerts'])]),
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'company' => ['name' => (string) ($tenant->legal_name ?: $tenant->name), 'tax_id' => $tenant->vat_number],
            'as_of' => $overview['as_of'],
            'currency' => $currency,
            'jurisdiction' => $overview['jurisdiction'],
            'overview' => $overview,
            'eba' => ['sections' => $sections],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function section(string $key, string $status, string $summaryKey, array $params): array
    {
        return ['key' => $key, 'title_key' => 'lpApi.eba.'.$key, 'status' => $status, 'summary_key' => $summaryKey, 'params' => $params];
    }
}
