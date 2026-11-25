<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use Modules\Intercompany\Enums\Standard;

/**
 * What differs in the presentation of the consolidated statements between Swiss GAAP FER 30, IFRS 10 (with IAS 1 and IAS 7)
 * and OIC 17 (with the Italian Civil Code layouts of art. 2424 and 2425 and OIC 10 for the cash flow). The figures are the
 * same; the profile decides the order of the sections, the layout of the income statement, the labels (by translation key,
 * never text) and the disclosures the package carries. It is a presentation of data the run already holds, not a second
 * calculation.
 */
final class StandardProfile
{
    /**
     * @return array{standard: string, framework: string, name_key: string, balance_sheet_order: list<string>, income_layout: string, income_sections: list<string>, nci_in_equity: bool, extraordinary_items: bool, goodwill_treatment: string, cash_flow_method: string, cash_flow_reference: string, translation_reserve_label_key: string, nci_label_key: string}
     */
    public static function for(Standard $standard): array
    {
        return match ($standard) {
            Standard::Fer30 => [
                'standard' => 'fer30',
                'framework' => 'Swiss GAAP FER 30',
                'name_key' => 'intercompany.standard.fer30',
                'balance_sheet_order' => ['assets', 'liabilities', 'equity'],
                'income_layout' => 'function',
                'income_sections' => ['revenue', 'cogs', 'operating_expenses'],
                'nci_in_equity' => true,
                'extraordinary_items' => true,
                'goodwill_treatment' => 'offset_or_capitalise',
                'cash_flow_method' => 'indirect',
                'cash_flow_reference' => 'FER 4',
                'translation_reserve_label_key' => 'intercompany.statements.fer30.translation_reserve',
                'nci_label_key' => 'intercompany.statements.fer30.nci',
            ],
            Standard::Ifrs10 => [
                'standard' => 'ifrs10',
                'framework' => 'IFRS 10',
                'name_key' => 'intercompany.standard.ifrs10',
                'balance_sheet_order' => ['assets', 'equity', 'liabilities'],
                'income_layout' => 'function',
                'income_sections' => ['revenue', 'cogs', 'operating_expenses'],
                'nci_in_equity' => true,
                'extraordinary_items' => false,
                'goodwill_treatment' => 'capitalise_impairment_only',
                'cash_flow_method' => 'indirect',
                'cash_flow_reference' => 'IAS 7',
                'translation_reserve_label_key' => 'intercompany.statements.ifrs10.translation_reserve',
                'nci_label_key' => 'intercompany.statements.ifrs10.nci',
            ],
            Standard::Oic17 => [
                'standard' => 'oic17',
                'framework' => 'OIC 17',
                'name_key' => 'intercompany.standard.oic17',
                'balance_sheet_order' => ['assets', 'equity', 'liabilities'],
                'income_layout' => 'nature',
                'income_sections' => ['value_of_production', 'cost_of_production'],
                'nci_in_equity' => true,
                'extraordinary_items' => false,
                'goodwill_treatment' => 'capitalise_amortise',
                'cash_flow_method' => 'indirect',
                'cash_flow_reference' => 'OIC 10',
                'translation_reserve_label_key' => 'intercompany.statements.oic17.translation_reserve',
                'nci_label_key' => 'intercompany.statements.oic17.nci',
            ],
        };
    }
}
