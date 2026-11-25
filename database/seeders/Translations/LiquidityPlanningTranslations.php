<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * Texts the liquidity planning API (Mod 017) produces itself: alert titles and messages, the statuses, tiers and categories of
 * the forecast, the presets, the EBA report sections, the notification email and the labels of the PDF and Excel exports. The web
 * client reads them like any other key (`lpApi.*`). Each entry is [it, en, de, fr]; placeholders are written {name}.
 */
final class LiquidityPlanningTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'lpApi.severity.info' => ['Informazione', 'Information', 'Information', 'Information'],
            'lpApi.severity.warning' => ['Attenzione', 'Warning', 'Warnung', 'Avertissement'],
            'lpApi.severity.critical' => ['Critico', 'Critical', 'Kritisch', 'Critique'],
            'lpApi.status.ok' => ['Regolare', 'In order', 'In Ordnung', 'En ordre'],
            'lpApi.status.watch' => ['Da monitorare', 'To monitor', 'Zu beobachten', 'À surveiller'],
            'lpApi.status.critical' => ['Critico', 'Critical', 'Kritisch', 'Critique'],
            'lpApi.alert_status.open' => ['Aperto', 'Open', 'Offen', 'Ouvert'],
            'lpApi.alert_status.acknowledged' => ['Preso in carico', 'Acknowledged', 'Zur Kenntnis genommen', 'Pris en charge'],
            'lpApi.alert_status.resolved' => ['Risolto', 'Resolved', 'Erledigt', 'Résolu'],

            'lpApi.tier.certain' => ['Certe', 'Certain', 'Sicher', 'Certains'],
            'lpApi.tier.probable' => ['Altamente probabili', 'Highly probable', 'Hochwahrscheinlich', 'Hautement probables'],
            'lpApi.tier.estimated' => ['Stimate', 'Estimated', 'Geschätzt', 'Estimés'],

            'lpApi.category.receivable' => ['Crediti verso clienti', 'Customer receivables', 'Kundenforderungen', 'Créances clients'],
            'lpApi.category.payable' => ['Debiti verso fornitori', 'Supplier payables', 'Lieferantenverbindlichkeiten', 'Dettes fournisseurs'],
            'lpApi.category.sales_order' => ['Ordini di vendita confermati', 'Confirmed sales orders', 'Bestätigte Verkaufsaufträge', 'Commandes clients confirmées'],
            'lpApi.category.purchase_order' => ['Ordini d’acquisto approvati', 'Approved purchase orders', 'Genehmigte Bestellungen', 'Commandes d’achat approuvées'],
            'lpApi.category.recurring_contract' => ['Canoni e contratti ricorrenti (incassi)', 'Recurring contracts and fees (inflows)', 'Wiederkehrende Verträge und Gebühren (Einnahmen)', 'Contrats et redevances récurrents (encaissements)'],
            'lpApi.category.lease' => ['Affitti e leasing', 'Leases and rents', 'Mieten und Leasing', 'Loyers et leasing'],
            'lpApi.category.payroll' => ['Personale (stipendi e oneri)', 'Personnel (salaries and charges)', 'Personal (Löhne und Sozialabgaben)', 'Personnel (salaires et charges)'],
            'lpApi.category.tax' => ['Imposte periodiche', 'Periodic taxes', 'Periodische Steuern', 'Impôts périodiques'],
            'lpApi.category.capex' => ['Investimenti (CapEx)', 'Investments (CapEx)', 'Investitionen (CapEx)', 'Investissements (CapEx)'],
            'lpApi.category.debt_principal' => ['Rimborso capitale finanziamenti', 'Loan principal repayment', 'Kapitalrückzahlung Darlehen', 'Remboursement du capital des prêts'],
            'lpApi.category.debt_interest' => ['Interessi sui finanziamenti', 'Loan interest', 'Darlehenszinsen', 'Intérêts des prêts'],
            'lpApi.category.other' => ['Altro', 'Other', 'Sonstiges', 'Autre'],
            'lpApi.category.additional_financing' => ['Nuovo finanziamento', 'Additional financing', 'Zusätzliche Finanzierung', 'Financement supplémentaire'],
            'lpApi.dscr_kind.operating_inflow' => ['Entrata operativa', 'Operating inflow', 'Operative Einnahme', 'Encaissement opérationnel'],
            'lpApi.dscr_kind.operating_outflow' => ['Uscita operativa', 'Operating outflow', 'Operative Ausgabe', 'Décaissement opérationnel'],
            'lpApi.dscr_kind.principal' => ['Quota capitale', 'Principal', 'Kapitalanteil', 'Part en capital'],
            'lpApi.dscr_kind.interest' => ['Quota interessi', 'Interest', 'Zinsanteil', 'Part d’intérêts'],

            'lpApi.message.overview' => ['Previsione di liquidità', 'Liquidity forecast', 'Liquiditätsprognose', 'Prévision de liquidité'],
            'lpApi.message.report' => ['Report per CdA e istituti di credito', 'Board and bank report', 'Bericht für Verwaltungsrat und Banken', 'Rapport pour le conseil et les banques'],
            'lpApi.message.settings' => ['Impostazioni di pianificazione della liquidità', 'Liquidity planning settings', 'Einstellungen der Liquiditätsplanung', 'Paramètres de planification de la liquidité'],
            'lpApi.message.settings_saved' => ['Impostazioni salvate', 'Settings saved', 'Einstellungen gespeichert', 'Paramètres enregistrés'],
            'lpApi.message.manual_items' => ['Voci manuali di previsione', 'Manual forecast items', 'Manuelle Prognosepositionen', 'Éléments de prévision manuels'],
            'lpApi.message.manual_item_saved' => ['Voce di previsione salvata', 'Forecast item saved', 'Prognoseposition gespeichert', 'Élément de prévision enregistré'],
            'lpApi.message.manual_item_deleted' => ['Voce di previsione eliminata', 'Forecast item deleted', 'Prognoseposition gelöscht', 'Élément de prévision supprimé'],
            'lpApi.message.presets' => ['Scenari predefiniti', 'Scenario presets', 'Vordefinierte Szenarien', 'Scénarios prédéfinis'],
            'lpApi.message.scenarios' => ['Scenari what-if salvati', 'Saved what-if scenarios', 'Gespeicherte What-if-Szenarien', 'Scénarios what-if enregistrés'],
            'lpApi.message.scenario_saved' => ['Scenario salvato', 'Scenario saved', 'Szenario gespeichert', 'Scénario enregistré'],
            'lpApi.message.scenario_deleted' => ['Scenario eliminato', 'Scenario deleted', 'Szenario gelöscht', 'Scénario supprimé'],
            'lpApi.message.alerts' => ['Allerte di liquidità', 'Liquidity alerts', 'Liquiditätswarnungen', 'Alertes de liquidité'],
            'lpApi.message.alerts_evaluated' => ['Allerte valutate', 'Alerts evaluated', 'Warnungen ausgewertet', 'Alertes évaluées'],
            'lpApi.message.alert_acknowledged' => ['Allerta presa in carico', 'Alert acknowledged', 'Warnung zur Kenntnis genommen', 'Alerte prise en charge'],
            'lpApi.message.alert_resolved' => ['Allerta risolta', 'Alert resolved', 'Warnung erledigt', 'Alerte résolue'],
            'lpApi.mail.footer' => ['Messaggio automatico di FatturaFacile · Pianificazione della liquidità', 'Automatic message from FatturaFacile · Liquidity planning', 'Automatische Nachricht von FatturaFacile · Liquiditätsplanung', 'Message automatique de FatturaFacile · Planification de la liquidité'],

            'lpApi.alert.negative_balance.title' => ['Saldo bancario previsto negativo', 'Projected bank balance turns negative', 'Prognostizierter Banksaldo wird negativ', 'Solde bancaire prévu négatif'],
            'lpApi.alert.negative_balance.message' => ['Dal {date} la liquidità prevista scende sotto zero; il minimo è {amount} {currency} il {min_date}.', 'From {date} the projected cash falls below zero; the lowest point is {amount} {currency} on {min_date}.', 'Ab dem {date} fällt die prognostizierte Liquidität unter null; der Tiefstand beträgt {amount} {currency} am {min_date}.', 'À partir du {date} la trésorerie prévue passe sous zéro ; le point bas est de {amount} {currency} le {min_date}.'],
            'lpApi.alert.minimum_liquidity.title' => ['Liquidità prevista sotto il minimo', 'Projected liquidity below the minimum', 'Prognostizierte Liquidität unter dem Minimum', 'Liquidité prévue sous le minimum'],
            'lpApi.alert.minimum_liquidity.message' => ['La liquidità prevista scende a {amount} {currency}, sotto la soglia minima di {minimum} {currency}.', 'Projected liquidity drops to {amount} {currency}, below the minimum threshold of {minimum} {currency}.', 'Die prognostizierte Liquidität sinkt auf {amount} {currency}, unter die Mindestschwelle von {minimum} {currency}.', 'La liquidité prévue descend à {amount} {currency}, sous le seuil minimal de {minimum} {currency}.'],
            'lpApi.alert.dscr.title' => ['DSCR a 6 mesi sotto soglia', '6-month DSCR below threshold', '6-Monats-DSCR unter dem Schwellenwert', 'DSCR à 6 mois sous le seuil'],
            'lpApi.alert.dscr.message' => ['Il DSCR è {value} (soglia {threshold}): flussi operativi {operating_cash_flow} {currency} contro un servizio del debito di {debt_service} {currency}.', 'The DSCR is {value} (threshold {threshold}): operating cash flow {operating_cash_flow} {currency} against debt service of {debt_service} {currency}.', 'Der DSCR beträgt {value} (Schwelle {threshold}): operativer Cashflow {operating_cash_flow} {currency} gegenüber einem Schuldendienst von {debt_service} {currency}.', 'Le DSCR est de {value} (seuil {threshold}) : flux opérationnels {operating_cash_flow} {currency} pour un service de la dette de {debt_service} {currency}.'],
            'lpApi.alert.capital.title' => ['Capitale sociale e patrimonio netto', 'Share capital and net equity', 'Aktienkapital und Eigenkapital', 'Capital social et capitaux propres'],
            'lpApi.alert.capital.watch' => ['Il patrimonio netto di {net_equity} {currency} indica una perdita rilevante rispetto al capitale sociale di {share_capital} {currency} (Art. 2446 c.c.).', 'Net equity of {net_equity} {currency} shows a significant loss against the share capital of {share_capital} {currency} (Art. 2446 c.c.).', 'Das Eigenkapital von {net_equity} {currency} zeigt einen erheblichen Verlust gegenüber dem Aktienkapital von {share_capital} {currency} (Art. 2446 ZGB/c.c.).', 'Les capitaux propres de {net_equity} {currency} révèlent une perte importante par rapport au capital social de {share_capital} {currency} (art. 2446 c.c.).'],
            'lpApi.alert.capital.half_capital_lost' => ['Perdita di oltre la metà del capitale sociale: patrimonio netto {net_equity} {currency}, metà del capitale {half_capital} {currency} (CO 725 / 725a, Art. 2446 c.c.).', 'Loss of more than half of the share capital: net equity {net_equity} {currency}, half of the capital {half_capital} {currency} (CO 725 / 725a, Art. 2446 c.c.).', 'Verlust von mehr als der Hälfte des Aktienkapitals: Eigenkapital {net_equity} {currency}, Hälfte des Kapitals {half_capital} {currency} (OR 725 / 725a, Art. 2446 c.c.).', 'Perte de plus de la moitié du capital social : capitaux propres {net_equity} {currency}, moitié du capital {half_capital} {currency} (CO 725 / 725a, art. 2446 c.c.).'],
            'lpApi.alert.capital.below_legal_minimum' => ['Il patrimonio netto di {net_equity} {currency} è sceso sotto il minimo legale del capitale (Art. 2447 c.c.).', 'Net equity of {net_equity} {currency} has fallen below the legal minimum capital (Art. 2447 c.c.).', 'Das Eigenkapital von {net_equity} {currency} ist unter das gesetzliche Mindestkapital gefallen (Art. 2447 c.c.).', 'Les capitaux propres de {net_equity} {currency} sont tombés sous le capital minimum légal (art. 2447 c.c.).'],
            'lpApi.alert.capital.over_indebted' => ['Il patrimonio netto è negativo ({net_equity} {currency}): rischio di sovraindebitamento (CO 725b).', 'Net equity is negative ({net_equity} {currency}): risk of over-indebtedness (CO 725b).', 'Das Eigenkapital ist negativ ({net_equity} {currency}): Gefahr der Überschuldung (OR 725b).', 'Les capitaux propres sont négatifs ({net_equity} {currency}) : risque de surendettement (CO 725b).'],
            'lpApi.alert.pfn.title' => ['Leva finanziaria PFN / EBITDA', 'PFN / EBITDA leverage', 'Verschuldungsgrad PFN / EBITDA', 'Levier financier PFN / EBITDA'],
            'lpApi.alert.pfn.message' => ['PFN {pfn} {currency}, EBITDA {ebitda} {currency}, rapporto {ratio} (massimo {max_ratio}).', 'PFN {pfn} {currency}, EBITDA {ebitda} {currency}, ratio {ratio} (maximum {max_ratio}).', 'PFN {pfn} {currency}, EBITDA {ebitda} {currency}, Verhältnis {ratio} (Maximum {max_ratio}).', 'PFN {pfn} {currency}, EBITDA {ebitda} {currency}, ratio {ratio} (maximum {max_ratio}).'],
            'lpApi.alert.covenant.title' => ['Covenant finanziario violato', 'Financial covenant breached', 'Finanzieller Covenant verletzt', 'Covenant financier violé'],
            'lpApi.alert.covenant.message' => ['Finanziamento {loan}: indicatore {metric} pari a {actual}, limite {limit}.', 'Loan {loan}: indicator {metric} is {actual}, limit {limit}.', 'Darlehen {loan}: Kennzahl {metric} beträgt {actual}, Grenzwert {limit}.', 'Prêt {loan} : l’indicateur {metric} vaut {actual}, limite {limit}.'],

            'lpApi.preset.collection_delay_20.name' => ['Ritardo incassi clienti (20%)', 'Customer collection delay (20%)', 'Verzögerte Kundenzahlungen (20 %)', 'Retard des encaissements clients (20 %)'],
            'lpApi.preset.collection_delay_20.description' => ['Il 20% degli incassi slitta di 30 giorni.', '20% of collections slip by 30 days.', '20 % der Zahlungseingänge verschieben sich um 30 Tage.', '20 % des encaissements sont décalés de 30 jours.'],
            'lpApi.preset.raw_material_spike.name' => ['Rincaro materie prime (+15%)', 'Raw material price spike (+15%)', 'Rohstoffpreisanstieg (+15 %)', 'Hausse des matières premières (+15 %)'],
            'lpApi.preset.raw_material_spike.description' => ['Ordini e debiti verso fornitori costano il 15% in più.', 'Supplier orders and payables cost 15% more.', 'Lieferantenbestellungen und -verbindlichkeiten kosten 15 % mehr.', 'Les commandes et dettes fournisseurs coûtent 15 % de plus.'],
            'lpApi.preset.capex_acceleration.name' => ['Anticipo investimenti', 'Capex acceleration', 'Vorgezogene Investitionen', 'Investissements anticipés'],
            'lpApi.preset.capex_acceleration.description' => ['Gli investimenti approvati sono anticipati di 90 giorni.', 'Approved investments are brought forward by 90 days.', 'Genehmigte Investitionen werden um 90 Tage vorgezogen.', 'Les investissements approuvés sont avancés de 90 jours.'],

            'lpApi.eba.cash_flow_sustainability' => ['Sostenibilità dei flussi di cassa', 'Cash flow sustainability', 'Nachhaltigkeit des Cashflows', 'Soutenabilité des flux de trésorerie'],
            'lpApi.eba.debt_service_capacity' => ['Capacità di servizio del debito', 'Debt service capacity', 'Schuldendienstfähigkeit', 'Capacité de service de la dette'],
            'lpApi.eba.capital_adequacy' => ['Adeguatezza patrimoniale', 'Capital adequacy', 'Kapitaladäquanz', 'Adéquation des fonds propres'],
            'lpApi.eba.leverage' => ['Leva finanziaria', 'Leverage', 'Verschuldung', 'Levier financier'],
            'lpApi.eba.stress_resilience' => ['Resilienza agli scenari di stress', 'Stress scenario resilience', 'Resilienz im Stressszenario', 'Résilience aux scénarios de stress'],
            'lpApi.eba.governance_oversight' => ['Governance e monitoraggio', 'Governance and monitoring', 'Governance und Überwachung', 'Gouvernance et suivi'],
            'lpApi.eba.cash_flow.ok' => ['La liquidità prevista resta positiva sull’orizzonte di 180 giorni: saldo finale {closing} {currency}, minimo {min} {currency} il {min_date}.', 'Projected liquidity stays positive over the 180-day horizon: closing balance {closing} {currency}, lowest {min} {currency} on {min_date}.', 'Die prognostizierte Liquidität bleibt über 180 Tage positiv: Endsaldo {closing} {currency}, Tiefstand {min} {currency} am {min_date}.', 'La liquidité prévue reste positive sur 180 jours : solde final {closing} {currency}, minimum {min} {currency} le {min_date}.'],
            'lpApi.eba.cash_flow.watch' => ['La liquidità prevista scende sotto il minimo fissato: saldo finale {closing} {currency}, minimo {min} {currency} il {min_date}.', 'Projected liquidity falls below the set minimum: closing balance {closing} {currency}, lowest {min} {currency} on {min_date}.', 'Die prognostizierte Liquidität fällt unter das festgelegte Minimum: Endsaldo {closing} {currency}, Tiefstand {min} {currency} am {min_date}.', 'La liquidité prévue passe sous le minimum fixé : solde final {closing} {currency}, minimum {min} {currency} le {min_date}.'],
            'lpApi.eba.cash_flow.negative' => ['Saldo previsto negativo dal {date}: minimo {min} {currency} il {min_date}.', 'Projected balance negative from {date}: lowest {min} {currency} on {min_date}.', 'Prognostizierter Saldo ab {date} negativ: Tiefstand {min} {currency} am {min_date}.', 'Solde prévu négatif à partir du {date} : minimum {min} {currency} le {min_date}.'],
            'lpApi.eba.dscr.healthy' => ['DSCR a 6 mesi pari a {value}: i flussi operativi ({operating} {currency}) coprono il servizio del debito ({service} {currency}).', '6-month DSCR of {value}: operating cash flow ({operating} {currency}) covers the debt service ({service} {currency}).', '6-Monats-DSCR von {value}: der operative Cashflow ({operating} {currency}) deckt den Schuldendienst ({service} {currency}).', 'DSCR à 6 mois de {value} : les flux opérationnels ({operating} {currency}) couvrent le service de la dette ({service} {currency}).'],
            'lpApi.eba.dscr.warning' => ['DSCR a 6 mesi pari a {value}, in fascia di attenzione: flussi operativi {operating} {currency}, servizio del debito {service} {currency}.', '6-month DSCR of {value} is in the warning band: operating cash flow {operating} {currency}, debt service {service} {currency}.', 'Der 6-Monats-DSCR von {value} liegt im Warnbereich: operativer Cashflow {operating} {currency}, Schuldendienst {service} {currency}.', 'DSCR à 6 mois de {value} en zone d’alerte : flux opérationnels {operating} {currency}, service de la dette {service} {currency}.'],
            'lpApi.eba.dscr.critical' => ['DSCR a 6 mesi pari a {value}, sotto la soglia critica: flussi operativi {operating} {currency}, servizio del debito {service} {currency}.', '6-month DSCR of {value} is below the critical threshold: operating cash flow {operating} {currency}, debt service {service} {currency}.', 'Der 6-Monats-DSCR von {value} liegt unter der kritischen Schwelle: operativer Cashflow {operating} {currency}, Schuldendienst {service} {currency}.', 'DSCR à 6 mois de {value} sous le seuil critique : flux opérationnels {operating} {currency}, service de la dette {service} {currency}.'],
            'lpApi.eba.dscr.not_applicable' => ['Nessun servizio del debito nei prossimi 6 mesi: il DSCR non è applicabile.', 'No debt service in the next 6 months: the DSCR does not apply.', 'Kein Schuldendienst in den nächsten 6 Monaten: der DSCR ist nicht anwendbar.', 'Aucun service de la dette dans les 6 prochains mois : le DSCR ne s’applique pas.'],
            'lpApi.eba.capital.ok' => ['Patrimonio netto {equity} {currency} a fronte di un capitale sociale di {capital} {currency} (copertura {coverage}%).', 'Net equity of {equity} {currency} against a share capital of {capital} {currency} (coverage {coverage}%).', 'Eigenkapital {equity} {currency} bei einem Aktienkapital von {capital} {currency} (Deckung {coverage} %).', 'Capitaux propres de {equity} {currency} pour un capital social de {capital} {currency} (couverture {coverage} %).'],
            'lpApi.eba.capital.watch' => ['Patrimonio netto {equity} {currency} inferiore al capitale sociale di {capital} {currency} (copertura {coverage}%): perdita da monitorare.', 'Net equity of {equity} {currency} is below the share capital of {capital} {currency} (coverage {coverage}%): loss to monitor.', 'Das Eigenkapital {equity} {currency} liegt unter dem Aktienkapital von {capital} {currency} (Deckung {coverage} %): Verlust beobachten.', 'Capitaux propres de {equity} {currency} inférieurs au capital social de {capital} {currency} (couverture {coverage} %) : perte à surveiller.'],
            'lpApi.eba.capital.half_capital_lost' => ['Perdita di oltre la metà del capitale sociale (CO 725 / Art. 2446 c.c.): patrimonio netto {equity} {currency}, capitale {capital} {currency}.', 'Loss of more than half of the share capital (CO 725 / Art. 2446 c.c.): net equity {equity} {currency}, capital {capital} {currency}.', 'Verlust von mehr als der Hälfte des Aktienkapitals (OR 725 / Art. 2446 c.c.): Eigenkapital {equity} {currency}, Kapital {capital} {currency}.', 'Perte de plus de la moitié du capital social (CO 725 / art. 2446 c.c.) : capitaux propres {equity} {currency}, capital {capital} {currency}.'],
            'lpApi.eba.capital.below_legal_minimum' => ['Capitale sotto il minimo legale (Art. 2447 c.c.): patrimonio netto {equity} {currency}.', 'Capital below the legal minimum (Art. 2447 c.c.): net equity {equity} {currency}.', 'Kapital unter dem gesetzlichen Minimum (Art. 2447 c.c.): Eigenkapital {equity} {currency}.', 'Capital sous le minimum légal (art. 2447 c.c.) : capitaux propres {equity} {currency}.'],
            'lpApi.eba.capital.over_indebted' => ['Patrimonio netto negativo ({equity} {currency}): rischio di sovraindebitamento (CO 725b).', 'Negative net equity ({equity} {currency}): risk of over-indebtedness (CO 725b).', 'Negatives Eigenkapital ({equity} {currency}): Überschuldungsgefahr (OR 725b).', 'Capitaux propres négatifs ({equity} {currency}) : risque de surendettement (CO 725b).'],
            'lpApi.eba.capital.not_configured' => ['Capitale sociale non configurato: indicare il capitale nelle impostazioni per attivare il monitoraggio (patrimonio netto {equity} {currency}).', 'Share capital not configured: enter it in the settings to enable monitoring (net equity {equity} {currency}).', 'Aktienkapital nicht konfiguriert: in den Einstellungen erfassen, um die Überwachung zu aktivieren (Eigenkapital {equity} {currency}).', 'Capital social non configuré : le saisir dans les paramètres pour activer le suivi (capitaux propres {equity} {currency}).'],
            'lpApi.eba.leverage.ok' => ['PFN {pfn} {currency}, EBITDA {ebitda} {currency}, PFN/EBITDA {ratio}: leva nei limiti.', 'PFN {pfn} {currency}, EBITDA {ebitda} {currency}, PFN/EBITDA {ratio}: leverage within limits.', 'PFN {pfn} {currency}, EBITDA {ebitda} {currency}, PFN/EBITDA {ratio}: Verschuldung im Rahmen.', 'PFN {pfn} {currency}, EBITDA {ebitda} {currency}, PFN/EBITDA {ratio} : levier dans les limites.'],
            'lpApi.eba.leverage.watch' => ['PFN/EBITDA {ratio} vicino al massimo consentito (PFN {pfn} {currency}, EBITDA {ebitda} {currency}).', 'PFN/EBITDA of {ratio} is close to the maximum allowed (PFN {pfn} {currency}, EBITDA {ebitda} {currency}).', 'PFN/EBITDA von {ratio} nahe am zulässigen Maximum (PFN {pfn} {currency}, EBITDA {ebitda} {currency}).', 'PFN/EBITDA de {ratio} proche du maximum autorisé (PFN {pfn} {currency}, EBITDA {ebitda} {currency}).'],
            'lpApi.eba.leverage.breach' => ['PFN/EBITDA oltre il massimo consentito (PFN {pfn} {currency}, EBITDA {ebitda} {currency}, rapporto {ratio}).', 'PFN/EBITDA above the maximum allowed (PFN {pfn} {currency}, EBITDA {ebitda} {currency}, ratio {ratio}).', 'PFN/EBITDA über dem zulässigen Maximum (PFN {pfn} {currency}, EBITDA {ebitda} {currency}, Verhältnis {ratio}).', 'PFN/EBITDA au-dessus du maximum autorisé (PFN {pfn} {currency}, EBITDA {ebitda} {currency}, ratio {ratio}).'],
            'lpApi.eba.leverage.not_applicable' => ['PFN {pfn} {currency}: EBITDA non disponibile o nullo, il rapporto PFN/EBITDA non è calcolabile.', 'PFN {pfn} {currency}: EBITDA unavailable or zero, the PFN/EBITDA ratio cannot be computed.', 'PFN {pfn} {currency}: EBITDA nicht verfügbar oder null, das Verhältnis PFN/EBITDA ist nicht berechenbar.', 'PFN {pfn} {currency} : EBITDA indisponible ou nul, le ratio PFN/EBITDA n’est pas calculable.'],
            'lpApi.eba.stress.summary' => ['Con il 20% degli incassi in ritardo di 30 giorni: saldo a 180 giorni {closing} {currency}, minimo {min} {currency}, DSCR {dscr}.', 'With 20% of collections delayed by 30 days: 180-day balance {closing} {currency}, lowest {min} {currency}, DSCR {dscr}.', 'Bei 20 % um 30 Tage verspäteten Zahlungseingängen: Saldo nach 180 Tagen {closing} {currency}, Tiefstand {min} {currency}, DSCR {dscr}.', 'Avec 20 % des encaissements retardés de 30 jours : solde à 180 jours {closing} {currency}, minimum {min} {currency}, DSCR {dscr}.'],
            'lpApi.eba.governance.summary' => ['Allerte attive di early warning: {alerts}.', 'Active early-warning alerts: {alerts}.', 'Aktive Frühwarnungen: {alerts}.', 'Alertes d’alerte précoce actives : {alerts}.'],
            'lpApi.report.title' => ['Report di pianificazione della liquidità per il CdA e gli istituti di credito', 'Liquidity planning report for the board and credit institutions', 'Liquiditätsplanungsbericht für Verwaltungsrat und Kreditinstitute', 'Rapport de planification de la liquidité pour le conseil et les établissements de crédit'],
            'lpApi.report.horizons' => ['Proiezioni di tesoreria rolling', 'Rolling cash projections', 'Rollierende Liquiditätsprognosen', 'Projections de trésorerie glissantes'],
            'lpApi.report.indicators' => ['Indicatori di crisi', 'Crisis indicators', 'Krisenindikatoren', 'Indicateurs de crise'],
            'lpApi.report.eba' => ['Monitoraggio secondo le linee guida EBA sulla concessione e il monitoraggio del credito', 'Monitoring under the EBA guidelines on loan origination and monitoring', 'Überwachung gemäss den EBA-Leitlinien zur Kreditvergabe und -überwachung', 'Suivi selon les orientations de l’ABE sur l’octroi et le suivi des prêts'],
            'lpApi.report.alerts' => ['Allerte attive', 'Active alerts', 'Aktive Warnungen', 'Alertes actives'],
            'lpApi.report.no_alerts' => ['Nessuna allerta attiva.', 'No active alerts.', 'Keine aktiven Warnungen.', 'Aucune alerte active.'],

            'lpApi.export.title' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'lpApi.export.as_of' => ['Data di riferimento', 'As of', 'Stichtag', 'Date de référence'],
            'lpApi.export.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'lpApi.export.opening_cash' => ['Liquidità iniziale', 'Opening cash', 'Anfangsliquidität', 'Trésorerie initiale'],
            'lpApi.export.horizon' => ['Orizzonte (giorni)', 'Horizon (days)', 'Horizont (Tage)', 'Horizon (jours)'],
            'lpApi.export.inflows' => ['Entrate', 'Inflows', 'Einnahmen', 'Encaissements'],
            'lpApi.export.outflows' => ['Uscite', 'Outflows', 'Ausgaben', 'Décaissements'],
            'lpApi.export.net' => ['Flusso netto', 'Net flow', 'Nettofluss', 'Flux net'],
            'lpApi.export.closing' => ['Saldo finale', 'Closing balance', 'Endsaldo', 'Solde final'],
            'lpApi.export.min_balance' => ['Saldo minimo', 'Lowest balance', 'Tiefster Saldo', 'Solde minimal'],
            'lpApi.export.scenario_closing' => ['Saldo finale (scenario)', 'Closing balance (scenario)', 'Endsaldo (Szenario)', 'Solde final (scénario)'],
            'lpApi.export.scenario_min_balance' => ['Saldo minimo (scenario)', 'Lowest balance (scenario)', 'Tiefster Saldo (Szenario)', 'Solde minimal (scénario)'],
            'lpApi.export.indicator' => ['Indicatore', 'Indicator', 'Kennzahl', 'Indicateur'],
            'lpApi.export.baseline' => ['Base', 'Baseline', 'Basis', 'Base'],
            'lpApi.export.scenario' => ['Scenario', 'Scenario', 'Szenario', 'Scénario'],
            'lpApi.export.date' => ['Data', 'Date', 'Datum', 'Date'],
            'lpApi.export.kind' => ['Tipo', 'Type', 'Art', 'Type'],
            'lpApi.export.category' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'lpApi.export.tier' => ['Affidabilità', 'Reliability', 'Zuverlässigkeit', 'Fiabilité'],
            'lpApi.export.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'lpApi.export.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'lpApi.export.severity' => ['Gravità', 'Severity', 'Schweregrad', 'Gravité'],
            'lpApi.export.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'lpApi.export.dscr' => ['DSCR a 6 mesi', '6-month DSCR', '6-Monats-DSCR', 'DSCR à 6 mois'],
            'lpApi.export.operating_cash_flow' => ['Flusso di cassa operativo', 'Operating cash flow', 'Operativer Cashflow', 'Flux de trésorerie opérationnel'],
            'lpApi.export.debt_service' => ['Servizio del debito', 'Debt service', 'Schuldendienst', 'Service de la dette'],
            'lpApi.export.net_equity' => ['Patrimonio netto', 'Net equity', 'Eigenkapital', 'Capitaux propres'],
            'lpApi.export.pfn' => ['Posizione finanziaria netta (PFN)', 'Net financial position (PFN)', 'Nettofinanzposition (PFN)', 'Position financière nette (PFN)'],
            'lpApi.export.ebitda' => ['EBITDA', 'EBITDA', 'EBITDA', 'EBITDA'],
            'lpApi.export.pfn_ebitda' => ['PFN / EBITDA', 'PFN / EBITDA', 'PFN / EBITDA', 'PFN / EBITDA'],
            'lpApi.export.sheet.summary' => ['Sintesi', 'Summary', 'Zusammenfassung', 'Synthèse'],
            'lpApi.export.sheet.daily' => ['Giornaliero', 'Daily', 'Täglich', 'Quotidien'],
            'lpApi.export.sheet.weekly' => ['Settimanale', 'Weekly', 'Wöchentlich', 'Hebdomadaire'],
            'lpApi.export.sheet.dscr' => ['Dettaglio DSCR', 'DSCR detail', 'DSCR-Detail', 'Détail DSCR'],
            'lpApi.export.sheet.alerts' => ['Allerte', 'Alerts', 'Warnungen', 'Alertes'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = match ($locale) {
            'it' => 0,
            'en' => 1,
            'de' => 2,
            'fr' => 3,
            default => 1,
        };

        return array_map(static fn (array $texts): string => $texts[$index], self::definitions());
    }
}
