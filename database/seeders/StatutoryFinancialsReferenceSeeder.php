<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\StatutoryFinancials\LineCatalogData;
use Database\Seeders\StatutoryFinancials\RatioDefinitionData;
use Database\Seeders\StatutoryFinancials\TextTemplateData;
use Illuminate\Database\Seeder;
use Modules\StatutoryFinancials\Models\AccountMapping;
use Modules\StatutoryFinancials\Models\LineDefinitionRecord;
use Modules\StatutoryFinancials\Models\RatioDefinition;
use Modules\StatutoryFinancials\Models\TextTemplate;

/**
 * Global reference data of the statutory financial statement dossier (Mod 167): the statement line catalogue with its labels, the
 * default chart of accounts -> XBRL / CO Art. 959 / Swiss GAAP FER line mapping (with the bifronte account rules), the narrative and
 * report templates (Nota Integrativa, Anhang, management report, minutes, validation messages) and the ratio and DSCR definitions.
 * Idempotent: every row is keyed and upserted, rows without a tenant (global). All wording is in it / en / de / fr.
 *
 * The mapping prefixes cover the two charts the platform ships (Italian piano dei conti and the Swiss KMU Kontenrahmen); a company
 * with its own chart overrides or extends them with tenant rows, or sets the statutory code of the account to a line code.
 */
final class StatutoryFinancialsReferenceSeeder extends Seeder
{
    /**
     * [family, code prefix, line, credit-side line (bifronte) or null, priority].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: ?string, 4: int}>
     */
    private const array Mappings = [
        // Italy: piano dei conti civilistico (two-digit classes).
        ['IT', '10', 'it.bs.b.i.7', null, 0], ['IT', '11', 'it.bs.b.ii.4', null, 0], ['IT', '12', 'it.bs.b.iii.1', null, 0],
        ['IT', '20', 'it.bs.c.i', null, 0], ['IT', '21', 'it.bs.c.ii.1', 'it.bs.deb.6', 0], ['IT', '22', 'it.bs.c.iv.1', 'it.bs.deb.4', 0],
        ['IT', '23', 'it.bs.d', null, 0], ['IT', '30', 'it.bs.pn.vi', null, 0], ['IT', '31', 'it.bs.fr.4', null, 0],
        ['IT', '32', 'it.bs.deb.14', 'it.bs.c.ii.5quater', 0], ['IT', '33', 'it.bs.rr', null, 0],
        ['IT', '40', 'it.is.a.1', null, 0], ['IT', '50', 'it.is.b.6', null, 0], ['IT', '51', 'it.is.b.7', null, 0], ['IT', '52', 'it.is.b.8', null, 0],
        ['IT', '53', 'it.is.b.9', null, 0], ['IT', '54', 'it.is.b.10', null, 0], ['IT', '55', 'it.is.b.14', null, 0], ['IT', '56', 'it.is.c.17', null, 0],
        ['IT', '57', 'it.is.tax', null, 0],
        // Switzerland: Kontenrahmen KMU. The longest prefix wins.
        ['CH', '1000', 'ch.bs.cash', null, 0], ['CH', '1020', 'ch.bs.cash', 'ch.bs.st_debt', 0], ['CH', '1021', 'ch.bs.cash', 'ch.bs.st_debt', 0],
        ['CH', '106', 'ch.bs.cash', null, 0], ['CH', '1100', 'ch.bs.trade_rec', 'ch.bs.other_st_pay', 0], ['CH', '1109', 'ch.bs.trade_rec', null, 0],
        ['CH', '114', 'ch.bs.other_rec', null, 0], ['CH', '117', 'ch.bs.other_rec', 'ch.bs.other_st_pay', 0], ['CH', '118', 'ch.bs.other_rec', 'ch.bs.other_st_pay', 0],
        ['CH', '12', 'ch.bs.inventory', null, 0], ['CH', '13', 'ch.bs.accr_assets', null, 0], ['CH', '14', 'ch.bs.fin_assets', null, 0],
        ['CH', '15', 'ch.bs.tangible', null, 0], ['CH', '16', 'ch.bs.tangible', null, 0], ['CH', '17', 'ch.bs.intangible', null, 0], ['CH', '1850', 'ch.bs.tangible', null, 0],
        ['CH', '2000', 'ch.bs.trade_pay', 'ch.bs.other_rec', 0], ['CH', '2030', 'ch.bs.other_st_pay', null, 0], ['CH', '21', 'ch.bs.st_debt', null, 0],
        ['CH', '22', 'ch.bs.other_st_pay', 'ch.bs.other_rec', 0], ['CH', '23', 'ch.bs.accr_liab', null, 0], ['CH', '2330', 'ch.bs.st_prov', null, 0],
        ['CH', '24', 'ch.bs.lt_debt', null, 0], ['CH', '26', 'ch.bs.lt_prov', null, 0],
        ['CH', '2800', 'ch.bs.share_capital', null, 0], ['CH', '2850', 'ch.bs.vol_res', null, 0], ['CH', '29', 'ch.bs.vol_res', null, 0],
        ['CH', '2900', 'ch.bs.legal_cap_res', null, 0], ['CH', '2950', 'ch.bs.legal_profit_res', null, 0], ['CH', '2970', 'ch.bs.retained', null, 0], ['CH', '2979', 'ch.bs.retained', null, 0],
        ['CH', '30', 'ch.is.tc.rev', null, 0], ['CH', '32', 'ch.is.tc.rev', null, 0], ['CH', '34', 'ch.is.tc.rev', null, 0], ['CH', '36', 'ch.is.tc.rev', null, 0], ['CH', '38', 'ch.is.tc.rev', null, 0],
        ['CH', '4', 'ch.is.tc.material', null, 0], ['CH', '4900', 'ch.is.tc.inv_change', null, 0], ['CH', '5', 'ch.is.tc.personnel', null, 0],
        ['CH', '6', 'ch.is.tc.other_opex', null, 0], ['CH', '68', 'ch.is.tc.depr', null, 0], ['CH', '69', 'ch.is.tc.fin_exp', null, 0], ['CH', '6950', 'ch.is.tc.fin_inc', null, 0],
        ['CH', '7', 'ch.is.tc.rev', null, 0], ['CH', '80', 'ch.is.tc.nonop', null, 0], ['CH', '81', 'ch.is.tc.nonop', null, 0],
        ['CH', '85', 'ch.is.tc.extra', null, 0], ['CH', '89', 'ch.is.tc.tax', null, 0],
    ];

    public function run(): void
    {
        foreach (LineCatalogData::all() as $row) {
            LineDefinitionRecord::updateOrCreateGlobal(['code' => $row['code']], collect($row)->except('code')->all());
        }

        foreach (self::Mappings as [$family, $prefix, $line, $alternative, $priority]) {
            AccountMapping::updateOrCreateGlobal(['family' => $family, 'code_prefix' => $prefix], [
                'line_code' => $line,
                'alt_line_code' => $alternative,
                'is_bifront' => $alternative !== null,
                'priority' => $priority,
            ]);
        }

        foreach (TextTemplateData::all() as $row) {
            TextTemplate::updateOrCreateGlobal(['template_key' => $row['template_key']], collect($row)->except('template_key')->all());
        }

        foreach (RatioDefinitionData::all() as $row) {
            RatioDefinition::updateOrCreateGlobal(['code' => $row['code']], collect($row)->except('code')->all());
        }
    }
}
