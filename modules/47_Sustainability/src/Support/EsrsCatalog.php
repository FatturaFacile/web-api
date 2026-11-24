<?php

declare(strict_types=1);

namespace Modules\Sustainability\Support;

/**
 * The default ESRS data point catalog installed per company: general disclosures (ESRS 2), environment (E1-E5), social
 * (S1-S4) and governance (G1). It is a working subset of the disclosure requirements and data points of the standards
 * (identifiers follow the disclosure requirement, for example E1-6), meant to be extended by the company: data points
 * can be added, deactivated or made optional.
 */
final class EsrsCatalog
{
    /**
     * Each entry: code, standard, disclosure, name, data type, unit, mandatory, auto source.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string|null, 6: bool, 7: string|null}>
     */
    public static function defaults(): array
    {
        return [
            ['ESRS2-BP-1', 'ESRS2', 'BP-1', 'Basis for preparation of the sustainability statement (scope of consolidation)', 'text', null, true, null],
            ['ESRS2-GOV-1', 'ESRS2', 'GOV-1', 'Role of the administrative, management and supervisory bodies', 'text', null, true, null],
            ['ESRS2-GOV-3', 'ESRS2', 'GOV-3', 'Integration of sustainability performance in incentive schemes', 'text', null, false, null],
            ['ESRS2-SBM-1', 'ESRS2', 'SBM-1', 'Strategy, business model and value chain', 'text', null, true, null],
            ['ESRS2-SBM-3', 'ESRS2', 'SBM-3', 'Material impacts, risks and opportunities and their interaction with strategy', 'text', null, true, null],
            ['ESRS2-IRO-1', 'ESRS2', 'IRO-1', 'Process to identify and assess material impacts, risks and opportunities', 'text', null, true, null],
            ['E1-1', 'E1', 'E1-1', 'Transition plan for climate change mitigation', 'text', null, true, null],
            ['E1-2', 'E1', 'E1-2', 'Policies related to climate change mitigation and adaptation', 'text', null, true, null],
            ['E1-4', 'E1', 'E1-4', 'Targets related to climate change mitigation and adaptation', 'text', null, true, null],
            ['E1-5-ENERGY', 'E1', 'E1-5', 'Total energy consumption', 'number', 'MWh', true, null],
            ['E1-5-RENEWABLE', 'E1', 'E1-5', 'Share of renewable sources in total energy consumption', 'number', '%', false, null],
            ['E1-6-SCOPE1', 'E1', 'E1-6', 'Gross Scope 1 greenhouse gas emissions', 'number', 'tCO2e', true, 'inventory.scope_1_tco2e'],
            ['E1-6-SCOPE2', 'E1', 'E1-6', 'Gross Scope 2 greenhouse gas emissions', 'number', 'tCO2e', true, 'inventory.scope_2_tco2e'],
            ['E1-6-SCOPE3', 'E1', 'E1-6', 'Gross Scope 3 greenhouse gas emissions', 'number', 'tCO2e', true, 'inventory.scope_3_tco2e'],
            ['E1-6-TOTAL', 'E1', 'E1-6', 'Total gross greenhouse gas emissions', 'number', 'tCO2e', true, 'inventory.total_tco2e'],
            ['E1-6-INTENSITY', 'E1', 'E1-6', 'Greenhouse gas intensity per net revenue', 'number', 'tCO2e/M', true, 'inventory.intensity_revenue'],
            ['E2-1', 'E2', 'E2-1', 'Policies related to pollution', 'text', null, true, null],
            ['E2-4-AIR', 'E2', 'E2-4', 'Emissions of pollutants to air', 'number', 'kg', true, null],
            ['E2-5-SOC', 'E2', 'E2-5', 'Substances of concern produced or used', 'text', null, false, null],
            ['E3-1', 'E3', 'E3-1', 'Policies related to water and marine resources', 'text', null, true, null],
            ['E3-4-WATER', 'E3', 'E3-4', 'Total water consumption', 'number', 'm3', true, null],
            ['E4-1', 'E4', 'E4-1', 'Transition plan and consideration of biodiversity and ecosystems in strategy', 'text', null, true, null],
            ['E4-5-SITES', 'E4', 'E4-5', 'Sites located in or near biodiversity-sensitive areas', 'number', 'count', false, null],
            ['E5-1', 'E5', 'E5-1', 'Policies related to resource use and circular economy', 'text', null, true, null],
            ['E5-4-INFLOW', 'E5', 'E5-4', 'Total resource inflows (materials used)', 'number', 'kg', true, null],
            ['E5-5-WASTE', 'E5', 'E5-5', 'Total waste generated', 'number', 'kg', true, null],
            ['S1-1', 'S1', 'S1-1', 'Policies related to own workforce', 'text', null, true, null],
            ['S1-6-HEADCOUNT', 'S1', 'S1-6', 'Total number of employees (full-time equivalents)', 'number', 'FTE', true, 'period.employees_fte'],
            ['S1-9-WOMEN-TOP', 'S1', 'S1-9', 'Share of women in top management', 'number', '%', true, null],
            ['S1-14-FATALITIES', 'S1', 'S1-14', 'Number of work-related fatalities', 'number', 'count', true, null],
            ['S1-14-INJURY-RATE', 'S1', 'S1-14', 'Rate of recordable work-related accidents', 'number', 'rate', true, null],
            ['S1-16-PAY-GAP', 'S1', 'S1-16', 'Gender pay gap', 'number', '%', true, null],
            ['S2-1', 'S2', 'S2-1', 'Policies related to value chain workers', 'text', null, true, null],
            ['S2-4', 'S2', 'S2-4', 'Actions on material impacts on value chain workers', 'text', null, true, null],
            ['S3-1', 'S3', 'S3-1', 'Policies related to affected communities', 'text', null, true, null],
            ['S3-4', 'S3', 'S3-4', 'Actions on material impacts on affected communities', 'text', null, true, null],
            ['S4-1', 'S4', 'S4-1', 'Policies related to consumers and end-users', 'text', null, true, null],
            ['S4-4', 'S4', 'S4-4', 'Actions on material impacts on consumers and end-users', 'text', null, true, null],
            ['G1-1', 'G1', 'G1-1', 'Business conduct policies and corporate culture', 'text', null, true, null],
            ['G1-3-INCIDENTS', 'G1', 'G1-3', 'Confirmed incidents of corruption or bribery', 'number', 'count', true, null],
            ['G1-6-PAYMENT-DAYS', 'G1', 'G1-6', 'Average number of days to pay an invoice from the start of the contractual payment term', 'number', 'days', true, null],
        ];
    }
}
