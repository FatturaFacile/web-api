<?php

declare(strict_types=1);

namespace Database\Seeders\StatutoryFinancials;

/**
 * Financial ratios and crisis indicators of the dossier (Mod 167): profitability (ROE, ROI, ROS, EBITDA), leverage (PFN, PFN/EBITDA),
 * liquidity and solvency, the six-month DSCR and the early-warning indicators of the Italian business crisis code (Art. 2086 c.c.,
 * Art. 13 D.Lgs. 14/2019) and the capital loss and over-indebtedness tests of Art. 725 / 725b CO.
 *
 * The thresholds are INDICATIVE defaults: the sector thresholds of the CNDCEC table in force (and the covenants of the company)
 * must be loaded as tenant overrides before the indicators are used for a decision.
 *
 * Row: [code, family, unit, direction, warning, critical, crisis, [labels it,en,de,fr], [descriptions it,en,de,fr]].
 */
final class RatioDefinitionData
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $generic = self::genericAlerts();
        $rows = [];
        $order = 0;

        foreach (self::definitions() as [$code, $family, $unit, $direction, $warning, $critical, $crisis, $labels, $descriptions]) {
            $alerts = [];

            foreach (['it', 'en', 'de', 'fr'] as $index => $locale) {
                $alerts[$locale] = self::specificAlert($code, $locale) ?? $generic[$locale];
            }

            $rows[] = [
                'code' => $code, 'family' => $family, 'unit' => $unit, 'direction' => $direction,
                'warning_threshold' => $warning, 'critical_threshold' => $critical, 'is_crisis_indicator' => $crisis, 'sort_order' => ++$order * 10,
                'labels' => ['it' => $labels[0], 'en' => $labels[1], 'de' => $labels[2], 'fr' => $labels[3]],
                'descriptions' => ['it' => $descriptions[0], 'en' => $descriptions[1], 'de' => $descriptions[2], 'fr' => $descriptions[3]],
                'alert_messages' => $alerts,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array{warning: string, critical: string}>
     */
    private static function genericAlerts(): array
    {
        return [
            'it' => ['warning' => 'Attenzione: {{label}} pari a {{value}}, vicino alla soglia di {{threshold}}.', 'critical' => 'Allerta: {{label}} pari a {{value}}, oltre la soglia di {{threshold}}.'],
            'en' => ['warning' => 'Warning: {{label}} is {{value}}, close to the threshold of {{threshold}}.', 'critical' => 'Alert: {{label}} is {{value}}, beyond the threshold of {{threshold}}.'],
            'de' => ['warning' => 'Warnung: {{label}} beträgt {{value}}, nahe am Schwellenwert von {{threshold}}.', 'critical' => 'Alarm: {{label}} beträgt {{value}}, jenseits des Schwellenwerts von {{threshold}}.'],
            'fr' => ['warning' => 'Attention: {{label}} s\'élève à {{value}}, proche du seuil de {{threshold}}.', 'critical' => 'Alerte: {{label}} s\'élève à {{value}}, au-delà du seuil de {{threshold}}.'],
        ];
    }

    /**
     * @return array{warning: string, critical: string}|null
     */
    private static function specificAlert(string $code, string $locale): ?array
    {
        $messages = [
            'DSCR_6M' => [
                'it' => ['warning' => 'DSCR a 6 mesi pari a {{value}}: margine ridotto sul servizio del debito (soglia {{threshold}}). Valutare gli adeguati assetti ex art. 2086 c.c.', 'critical' => 'DSCR a 6 mesi pari a {{value}}, inferiore a {{threshold}}: i flussi attesi non coprono il debito in scadenza. Segnale di crisi ex art. 2086 c.c. e art. 3 D.Lgs. 14/2019.'],
                'en' => ['warning' => 'Six-month DSCR is {{value}}: thin margin on debt service (threshold {{threshold}}). Review the adequate structures under art. 2086 Civil Code.', 'critical' => 'Six-month DSCR is {{value}}, below {{threshold}}: expected cash flows do not cover debt falling due. Crisis signal under art. 2086 Civil Code and art. 3 Legislative Decree 14/2019.'],
                'de' => ['warning' => 'DSCR über 6 Monate beträgt {{value}}: geringer Spielraum beim Schuldendienst (Schwelle {{threshold}}). Angemessene Strukturen nach Art. 2086 ZGB-IT prüfen.', 'critical' => 'DSCR über 6 Monate beträgt {{value}}, unter {{threshold}}: die erwarteten Geldflüsse decken die fälligen Schulden nicht. Krisensignal nach Art. 2086 ZGB-IT und Art. 3 GvD 14/2019.'],
                'fr' => ['warning' => 'Le DSCR à 6 mois est de {{value}}: marge réduite sur le service de la dette (seuil {{threshold}}). Revoir les structures adéquates selon l\'art. 2086 c.c.', 'critical' => 'Le DSCR à 6 mois est de {{value}}, inférieur à {{threshold}}: les flux attendus ne couvrent pas la dette échue. Signal de crise selon l\'art. 2086 c.c. et l\'art. 3 D.Lgs. 14/2019.'],
            ],
            'NEGATIVE_EQUITY' => [
                'it' => ['warning' => 'Patrimonio netto ridotto ({{value}}).', 'critical' => 'Patrimonio netto negativo ({{value}}): indicatore di crisi; verificare gli obblighi degli artt. 2447 e 2482-ter c.c.'],
                'en' => ['warning' => 'Equity is low ({{value}}).', 'critical' => 'Equity is negative ({{value}}): crisis indicator; check the duties under arts. 2447 and 2482-ter Civil Code.'],
                'de' => ['warning' => 'Eigenkapital gering ({{value}}).', 'critical' => 'Eigenkapital negativ ({{value}}): Krisenindikator; Pflichten nach Art. 2447 und 2482-ter ZGB-IT prüfen.'],
                'fr' => ['warning' => 'Capitaux propres faibles ({{value}}).', 'critical' => 'Capitaux propres négatifs ({{value}}): indicateur de crise; vérifier les obligations des art. 2447 et 2482-ter c.c.'],
            ],
            'CAPITAL_LOSS_725' => [
                'it' => ['warning' => 'Il capitale proprio si avvicina alla metà del capitale azionario e delle riserve legali ({{value}}).', 'critical' => 'Perdita di capitale ai sensi dell\'art. 725a CO ({{value}}): il consiglio deve convocare l\'assemblea e proporre misure di risanamento.'],
                'en' => ['warning' => 'Equity is approaching half of the share capital and legal reserves ({{value}}).', 'critical' => 'Capital loss under art. 725a CO ({{value}}): the board must call a general meeting and propose restructuring measures.'],
                'de' => ['warning' => 'Das Eigenkapital nähert sich der Hälfte von Aktienkapital und gesetzlichen Reserven ({{value}}).', 'critical' => 'Kapitalverlust nach Art. 725a OR ({{value}}): der Verwaltungsrat muss die Generalversammlung einberufen und Sanierungsmassnahmen beantragen.'],
                'fr' => ['warning' => 'Les fonds propres approchent la moitié du capital-actions et des réserves légales ({{value}}).', 'critical' => 'Perte de capital selon l\'art. 725a CO ({{value}}): le conseil doit convoquer l\'assemblée générale et proposer des mesures d\'assainissement.'],
            ],
            'OVERINDEBTEDNESS_725B' => [
                'it' => ['warning' => 'Copertura dei debiti ridotta ({{value}}).', 'critical' => 'Indebitamento eccessivo ai sensi dell\'art. 725b CO ({{value}}): i debiti non sono più coperti; è richiesto il bilancio intermedio e, se del caso, l\'avviso al giudice.'],
                'en' => ['warning' => 'Debt coverage is thin ({{value}}).', 'critical' => 'Over-indebtedness under art. 725b CO ({{value}}): liabilities are no longer covered; an interim balance sheet and, where required, notice to the court are due.'],
                'de' => ['warning' => 'Schuldendeckung knapp ({{value}}).', 'critical' => 'Überschuldung nach Art. 725b OR ({{value}}): die Verbindlichkeiten sind nicht mehr gedeckt; Zwischenabschluss und gegebenenfalls Benachrichtigung des Gerichts erforderlich.'],
                'fr' => ['warning' => 'Couverture des dettes réduite ({{value}}).', 'critical' => 'Surendettement selon l\'art. 725b CO ({{value}}): les dettes ne sont plus couvertes; un bilan intermédiaire et, le cas échéant, l\'avis au juge sont requis.'],
            ],
        ];

        return $messages[$code][$locale] ?? null;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: ?string, 5: ?string, 6: bool, 7: list<string>, 8: list<string>}>
     */
    private static function definitions(): array
    {
        return [
            ['ROE', 'ALL', 'percent', null, null, null, false, ['ROE (rendimento del capitale proprio)', 'ROE (return on equity)', 'ROE (Eigenkapitalrendite)', 'ROE (rentabilité des capitaux propres)'], ['Utile dell\'esercizio / patrimonio netto.', 'Profit for the year / equity.', 'Jahresergebnis / Eigenkapital.', 'Résultat de l\'exercice / capitaux propres.']],
            ['ROI', 'ALL', 'percent', null, null, null, false, ['ROI (rendimento del capitale investito)', 'ROI (return on investment)', 'ROI (Gesamtkapitalrendite)', 'ROI (rentabilité du capital investi)'], ['Risultato operativo / totale attivo.', 'Operating result / total assets.', 'Betriebsergebnis / Bilanzsumme.', 'Résultat d\'exploitation / total de l\'actif.']],
            ['ROS', 'ALL', 'percent', null, null, null, false, ['ROS (redditività delle vendite)', 'ROS (return on sales)', 'ROS (Umsatzrendite)', 'ROS (rentabilité des ventes)'], ['Risultato operativo / ricavi.', 'Operating result / revenue.', 'Betriebsergebnis / Umsatz.', 'Résultat d\'exploitation / chiffre d\'affaires.']],
            ['EBITDA', 'ALL', 'amount', null, null, null, false, ['EBITDA', 'EBITDA', 'EBITDA', 'EBITDA'], ['Risultato operativo più ammortamenti e svalutazioni.', 'Operating result plus depreciation, amortisation and write-downs.', 'Betriebsergebnis zuzüglich Abschreibungen und Wertberichtigungen.', 'Résultat d\'exploitation plus amortissements et dépréciations.']],
            ['PFN', 'ALL', 'amount', null, null, null, false, ['Posizione finanziaria netta (PFN)', 'Net financial position (PFN)', 'Nettofinanzposition (PFN)', 'Position financière nette (PFN)'], ['Debiti finanziari meno disponibilità liquide.', 'Financial debt less cash and cash equivalents.', 'Finanzverbindlichkeiten abzüglich flüssiger Mittel.', 'Dettes financières moins disponibilités.']],
            ['PFN_EBITDA', 'ALL', 'ratio', 'max', '3.0000', '5.0000', false, ['PFN / EBITDA', 'PFN / EBITDA', 'PFN / EBITDA', 'PFN / EBITDA'], ['Anni di EBITDA necessari per ripagare la posizione finanziaria netta.', 'Years of EBITDA needed to repay the net financial position.', 'Jahre EBITDA zur Rückzahlung der Nettofinanzposition.', 'Années d\'EBITDA nécessaires pour rembourser la position financière nette.']],
            ['CURRENT_RATIO', 'ALL', 'ratio', 'min', '1.2000', '1.0000', false, ['Indice di liquidità corrente', 'Current ratio', 'Liquiditätsgrad 3', 'Ratio de liquidité générale'], ['Attivo circolante / passività correnti.', 'Current assets / current liabilities.', 'Umlaufvermögen / kurzfristiges Fremdkapital.', 'Actif circulant / dettes à court terme.']],
            ['QUICK_RATIO', 'ALL', 'ratio', 'min', '1.0000', '0.7000', false, ['Indice di liquidità secca', 'Quick ratio', 'Liquiditätsgrad 2', 'Ratio de liquidité réduite'], ['(Attivo circolante - rimanenze) / passività correnti.', '(Current assets - inventories) / current liabilities.', '(Umlaufvermögen - Vorräte) / kurzfristiges Fremdkapital.', '(Actif circulant - stocks) / dettes à court terme.']],
            ['EQUITY_RATIO', 'ALL', 'percent', 'min', '20.0000', '10.0000', false, ['Indice di autonomia finanziaria', 'Equity ratio', 'Eigenkapitalquote', 'Ratio d\'autonomie financière'], ['Patrimonio netto / totale attivo.', 'Equity / total assets.', 'Eigenkapital / Bilanzsumme.', 'Capitaux propres / total de l\'actif.']],
            ['DSCR_6M', 'ALL', 'ratio', 'min', '1.2000', '1.0000', true, ['DSCR a 6 mesi', 'Six-month DSCR', 'DSCR über 6 Monate', 'DSCR à 6 mois'], ['Flussi di cassa liberi attesi nei sei mesi / debito da servire nei sei mesi (art. 13 D.Lgs. 14/2019).', 'Expected free cash flow over six months / debt to be serviced over six months (art. 13 Legislative Decree 14/2019).', 'Erwarteter freier Geldfluss über sechs Monate / Schuldendienst über sechs Monate (Art. 13 GvD 14/2019).', 'Flux de trésorerie libres attendus sur six mois / service de la dette sur six mois (art. 13 D.Lgs. 14/2019).']],
            ['NEGATIVE_EQUITY', 'ALL', 'amount', 'min', '0.0000', '0.0000', true, ['Patrimonio netto', 'Equity', 'Eigenkapital', 'Capitaux propres'], ['Un patrimonio netto negativo è un indicatore di crisi.', 'Negative equity is a crisis indicator.', 'Negatives Eigenkapital ist ein Krisenindikator.', 'Des capitaux propres négatifs sont un indicateur de crise.']],
            ['FIN_CHARGES_REVENUE', 'IT', 'percent', 'max', '2.0000', '3.0000', true, ['Oneri finanziari / ricavi', 'Financial charges / revenue', 'Finanzaufwand / Umsatz', 'Charges financières / chiffre d\'affaires'], ['Indice di sostenibilità degli oneri finanziari; soglia di settore da verificare.', 'Sustainability of financial charges; sector threshold to be checked.', 'Tragbarkeit des Finanzaufwands; Branchenschwelle zu prüfen.', 'Soutenabilité des charges financières; seuil sectoriel à vérifier.']],
            ['EQUITY_DEBT', 'IT', 'percent', 'min', '5.0000', '2.3000', true, ['Patrimonio netto / debiti totali', 'Equity / total debt', 'Eigenkapital / Gesamtschulden', 'Capitaux propres / dettes totales'], ['Adeguatezza patrimoniale; soglia di settore da verificare.', 'Capital adequacy; sector threshold to be checked.', 'Kapitaladäquanz; Branchenschwelle zu prüfen.', 'Adéquation patrimoniale; seuil sectoriel à vérifier.']],
            ['CASH_FLOW_ASSETS', 'IT', 'percent', 'min', '1.0000', '0.5000', true, ['Cash flow / totale attivo', 'Cash flow / total assets', 'Cashflow / Bilanzsumme', 'Cash-flow / total de l\'actif'], ['Ritorno liquido dell\'attivo; soglia di settore da verificare.', 'Cash return on assets; sector threshold to be checked.', 'Liquider Ertrag der Aktiven; Branchenschwelle zu prüfen.', 'Rendement de trésorerie de l\'actif; seuil sectoriel à vérifier.']],
            ['LIQUIDITY_SHORT', 'IT', 'percent', 'min', '100.0000', '70.0000', true, ['Attivo a breve / passivo a breve', 'Short-term assets / short-term liabilities', 'Kurzfristige Aktiven / kurzfristige Passiven', 'Actif à court terme / passif à court terme'], ['Indice di liquidità; soglia di settore da verificare.', 'Liquidity index; sector threshold to be checked.', 'Liquiditätsindex; Branchenschwelle zu prüfen.', 'Indice de liquidité; seuil sectoriel à vérifier.']],
            ['TAX_SOCIAL_DEBT_ASSETS', 'IT', 'percent', 'max', '10.0000', '14.0000', true, ['Debiti previdenziali e tributari / totale attivo', 'Tax and social security debt / total assets', 'Steuer- und Sozialversicherungsschulden / Bilanzsumme', 'Dettes fiscales et sociales / total de l\'actif'], ['Indebitamento previdenziale e tributario; soglia di settore da verificare.', 'Tax and social security indebtedness; sector threshold to be checked.', 'Steuer- und Sozialversicherungsverschuldung; Branchenschwelle zu prüfen.', 'Endettement fiscal et social; seuil sectoriel à vérifier.']],
            ['CAPITAL_LOSS_725', 'CH', 'percent', 'min', '60.0000', '50.0000', true, ['Copertura del capitale (art. 725a CO)', 'Capital coverage (art. 725a CO)', 'Kapitaldeckung (Art. 725a OR)', 'Couverture du capital (art. 725a CO)'], ['Capitale proprio in percentuale di capitale azionario e riserve legali: sotto il 50% vi è perdita di capitale.', 'Equity as a percentage of share capital and legal reserves: below 50% there is a capital loss.', 'Eigenkapital in Prozent von Aktienkapital und gesetzlichen Reserven: unter 50% liegt ein Kapitalverlust vor.', 'Fonds propres en pourcentage du capital-actions et des réserves légales: sous 50%, il y a perte de capital.']],
            ['OVERINDEBTEDNESS_725B', 'CH', 'percent', 'min', '110.0000', '100.0000', true, ['Copertura dei debiti (art. 725b CO)', 'Debt coverage (art. 725b CO)', 'Schuldendeckung (Art. 725b OR)', 'Couverture des dettes (art. 725b CO)'], ['Totale attivo / capitale di terzi: sotto il 100% i debiti non sono più coperti.', 'Total assets / liabilities: below 100% liabilities are no longer covered.', 'Bilanzsumme / Fremdkapital: unter 100% sind die Schulden nicht mehr gedeckt.', 'Total de l\'actif / capitaux étrangers: sous 100%, les dettes ne sont plus couvertes.']],
        ];
    }
}
