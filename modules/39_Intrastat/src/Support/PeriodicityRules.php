<?php

declare(strict_types=1);

namespace Modules\Intrastat\Support;

/**
 * Thresholds that decide the periodicity of the INTRA lists and which data they must carry. Each category is measured
 * on its own, on the quarterly totals of the four previous quarters.
 *
 * Sources:
 * - Cessioni di beni and servizi resi, quarterly when every quarterly total of the four previous quarters, for each
 *   category, is "non superiore a 50mila euro", monthly otherwise: Agenzia delle Entrate, scheda "Periodicità elenchi
 *   Intrastat" (art. 2 DM 22 febbraio 2010):
 *   https://www.agenziaentrate.gov.it/portale/schede/dichiarazioni/elenchi-riepilogativi-intrastat/periodicita-elenchi-intrastat
 * - Statistical data of the monthly INTRA-1 bis optional unless a quarterly total of cessioni di beni was "uguale o
 *   superiore a 100.000 euro" in one of the four previous quarters: determinazione congiunta Agenzia delle Entrate /
 *   ADM prot. n. 194409 del 25 settembre 2017, punto 3.1.
 * - Servizi ricevuti (INTRA-2 quater), monthly, statistical only, when a quarterly total is "uguale o superiore a
 *   100.000 euro" in one of the four previous quarters: same determinazione 194409/2017, punto 2.1; the quarterly
 *   INTRA-2 lists are abolished from 2022 by the determinazione ADM prot. n. 493869/RU del 23 dicembre 2021, art. 4.
 * - Acquisti di beni (INTRA-2 bis), monthly, statistical only, when a quarterly total is "uguale o superiore a
 *   2.000.000 di euro" in one of the four previous quarters, for the lists due from 25 February 2026 (periods from
 *   January 2026): determinazione ADM prot. n. 84415 del 3 febbraio 2026, art. 1 and art. 4:
 *   https://www.adm.gov.it/portale/documents/20182/6100183/84415+-+03022026+-+Determinazione+direttoriale+ADM+-+Agenzia+Entrate+-+Istat04022026.pdf/cdcfdee5-971b-56e2-36a9-0f303d9cf605?version=1.0&t=1770215376091
 *   For periods of 2022 to 2025 the threshold was 350.000 euro: determinazione 493869/RU, art. 1:
 *   https://www.adm.gov.it/portale/documents/20182/23456462/Determinazione+prot+493869+RU.pdf/9b28c5ae-5786-4fd1-58ea-f9026c724cc9?t=1640615258235
 * - Parties with annual dispatches (or arrivals) above 20.000.000 euro in the previous year (art. 6 comma 4 DM 22
 *   febbraio 2010) also give natura della transazione B, statistical value, delivery terms and transport mode:
 *   determinazione 493869/RU, art. 2, and Allegato XI, Titolo II Sezione 1, col. 6.
 */
final class PeriodicityRules
{
    /**
     * Cessioni di beni and servizi resi: quarterly while no quarter exceeds this amount.
     */
    public const int SalesQuarterlyCeiling = 50_000;

    /**
     * Cessioni di beni: statistical data required in the monthly lists from this quarterly amount.
     */
    public const int SalesStatisticalDataThreshold = 100_000;

    /**
     * Servizi ricevuti: monthly list required from this quarterly amount.
     */
    public const int ServicesReceivedMonthlyThreshold = 100_000;

    /**
     * Acquisti di beni, periods from January 2026.
     */
    public const int GoodsPurchasesMonthlyThreshold = 2_000_000;

    /**
     * Acquisti di beni, periods of 2022 to 2025.
     */
    public const int GoodsPurchasesMonthlyThresholdUntil2025 = 350_000;

    /**
     * Annual dispatches or arrivals above which the extra statistical data are due.
     */
    public const int AnnualFlowsForExtendedStatistics = 20_000_000;

    /**
     * First year handled by this module: the models and the technical specifications of determinazione 493869/RU apply
     * to the periods from 1 January 2022.
     */
    public const int FirstSupportedYear = 2022;

    public static function goodsPurchasesThreshold(int $year): int
    {
        return $year >= 2026 ? self::GoodsPurchasesMonthlyThreshold : self::GoodsPurchasesMonthlyThresholdUntil2025;
    }
}
