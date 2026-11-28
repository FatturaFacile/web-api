<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * Texts the corporate crisis monitoring API (Mod 357) produces itself: statuses, early-warning indicators, qualified creditor
 * checks, the CNC practical test, ATECO sections, stress scenarios, API messages and the labels of the board dossier and of the
 * subordination agreement (Rangrücktritt) PDFs. The web client reads them like any other key (`crisisApi.*`). Each entry is
 * [it, en, de, fr]; placeholders are written {name}.
 */
final class CrisisMonitoringTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'crisisApi.status.green' => ['Regolare', 'In order', 'In Ordnung', 'En ordre'],
            'crisisApi.status.yellow' => ['Da monitorare', 'To monitor', 'Zu beobachten', 'À surveiller'],
            'crisisApi.status.red' => ['Allerta', 'Alert', 'Alarm', 'Alerte'],
            'crisisApi.horizon.m6' => ['6 mesi', '6 months', '6 Monate', '6 mois'],
            'crisisApi.horizon.m12' => ['12 mesi', '12 months', '12 Monate', '12 mois'],
            'crisisApi.comparison.min' => ['Minimo richiesto: il valore deve restare sopra la soglia', 'Minimum required: the value must stay above the threshold', 'Mindestwert: Der Wert muss über dem Schwellenwert bleiben', 'Minimum requis : la valeur doit rester au-dessus du seuil'],
            'crisisApi.comparison.max' => ['Massimo consentito: il valore deve restare sotto la soglia', 'Maximum allowed: the value must stay below the threshold', 'Höchstwert: Der Wert muss unter dem Schwellenwert bleiben', 'Maximum autorisé : la valeur doit rester en dessous du seuil'],

            'crisisApi.dscr.treasury' => ['DSCR – approccio di tesoreria (approccio 1 CNDCEC)', 'DSCR – treasury approach (CNDCEC approach 1)', 'DSCR – Liquiditätsansatz (CNDCEC-Ansatz 1)', 'DSCR – approche de trésorerie (approche 1 CNDCEC)'],
            'crisisApi.dscr.operating' => ['DSCR – approccio del flusso di cassa operativo (approccio 2 CNDCEC)', 'DSCR – operating cash flow approach (CNDCEC approach 2)', 'DSCR – Ansatz des operativen Cashflows (CNDCEC-Ansatz 2)', 'DSCR – approche du flux de trésorerie opérationnel (approche 2 CNDCEC)'],

            'crisisApi.indicator.net_equity' => ['Patrimonio netto negativo o inferiore al minimo legale', 'Net equity negative or below the legal minimum', 'Negatives Eigenkapital oder Eigenkapital unter dem gesetzlichen Minimum', 'Capitaux propres négatifs ou inférieurs au minimum légal'],
            'crisisApi.indicator.net_equity.description' => ['Segnala la perdita del capitale: un patrimonio netto negativo o sotto il minimo di legge è il primo indizio di crisi.', 'Flags capital loss: negative net equity or equity below the legal minimum is the first sign of a crisis.', 'Zeigt einen Kapitalverlust an: Ein negatives Eigenkapital oder ein Eigenkapital unter dem gesetzlichen Minimum ist das erste Krisenzeichen.', 'Signale la perte de capital : des capitaux propres négatifs ou inférieurs au minimum légal sont le premier signe de crise.'],
            'crisisApi.indicator.dscr_6m' => ['DSCR dei prossimi 6 mesi', 'DSCR over the next 6 months', 'DSCR der nächsten 6 Monate', 'DSCR des 6 prochains mois'],
            'crisisApi.indicator.dscr_6m.description' => ['Misura se i flussi di cassa attesi nei prossimi 6 mesi coprono il servizio del debito previsto nello stesso periodo.', 'Measures whether the cash flows expected over the next 6 months cover the debt service due in the same period.', 'Misst, ob die in den nächsten 6 Monaten erwarteten Geldflüsse den im gleichen Zeitraum fälligen Schuldendienst decken.', 'Mesure si les flux de trésorerie attendus sur les 6 prochains mois couvrent le service de la dette dû sur la même période.'],
            'crisisApi.indicator.financial_charges_revenue' => ['Oneri finanziari / ricavi', 'Financial charges / revenue', 'Finanzaufwand / Umsatz', 'Charges financières / chiffre d’affaires'],
            'crisisApi.indicator.financial_charges_revenue.description' => ['Indica quanta parte dei ricavi è assorbita dagli oneri finanziari: valori elevati segnalano un indebitamento oneroso.', 'Shows how much of the revenue is absorbed by financial charges: high values point to burdensome debt.', 'Zeigt, welcher Teil des Umsatzes vom Finanzaufwand aufgezehrt wird: Hohe Werte deuten auf eine belastende Verschuldung hin.', 'Indique la part du chiffre d’affaires absorbée par les charges financières : des valeurs élevées révèlent un endettement pesant.'],
            'crisisApi.indicator.equity_total_debt' => ['Patrimonio netto / debiti totali', 'Net equity / total debt', 'Eigenkapital / Gesamtschulden', 'Capitaux propres / dettes totales'],
            'crisisApi.indicator.equity_total_debt.description' => ['Esprime il grado di capitalizzazione rispetto ai debiti: più è basso, più l’impresa dipende da capitale di terzi.', 'Expresses the degree of capitalisation compared with the debt: the lower it is, the more the company depends on third-party capital.', 'Drückt den Kapitalisierungsgrad im Verhältnis zu den Schulden aus: Je tiefer der Wert, desto stärker ist das Unternehmen von Fremdkapital abhängig.', 'Exprime le niveau de capitalisation par rapport aux dettes : plus il est faible, plus l’entreprise dépend de capitaux de tiers.'],
            'crisisApi.indicator.liquidity_ratio' => ['Attivo corrente / passivo corrente', 'Current assets / current liabilities', 'Umlaufvermögen / kurzfristige Verbindlichkeiten', 'Actif circulant / passif à court terme'],
            'crisisApi.indicator.liquidity_ratio.description' => ['Verifica se le attività a breve termine bastano a coprire le passività a breve termine.', 'Checks whether short-term assets are sufficient to cover short-term liabilities.', 'Prüft, ob die kurzfristigen Aktiven ausreichen, um die kurzfristigen Verbindlichkeiten zu decken.', 'Vérifie si les actifs à court terme suffisent à couvrir les passifs à court terme.'],
            'crisisApi.indicator.cash_flow_assets' => ['Cash flow / attivo totale', 'Cash flow / total assets', 'Cashflow / Gesamtvermögen', 'Flux de trésorerie / total de l’actif'],
            'crisisApi.indicator.cash_flow_assets.description' => ['Misura la capacità del patrimonio aziendale di generare liquidità: valori bassi o negativi indicano una gestione che non si autofinanzia.', 'Measures how well the company’s assets generate cash: low or negative values show an operation that does not fund itself.', 'Misst, wie gut das Vermögen des Unternehmens Liquidität erzeugt: Tiefe oder negative Werte zeigen einen Betrieb, der sich nicht selbst finanziert.', 'Mesure la capacité des actifs de l’entreprise à générer de la trésorerie : des valeurs faibles ou négatives révèlent une exploitation qui ne s’autofinance pas.'],
            'crisisApi.indicator.tax_social_debt_assets' => ['Debiti tributari e previdenziali / attivo totale', 'Tax and social security debt / total assets', 'Steuer- und Sozialversicherungsschulden / Gesamtvermögen', 'Dettes fiscales et sociales / total de l’actif'],
            'crisisApi.indicator.tax_social_debt_assets.description' => ['Indica il peso dei debiti verso erario ed enti previdenziali sull’attivo: un valore alto segnala tensioni nei pagamenti verso creditori pubblici.', 'Shows the weight of debts to the tax authorities and social security bodies relative to assets: a high value signals strain in payments to public creditors.', 'Zeigt das Gewicht der Schulden gegenüber Steuerbehörden und Sozialversicherungen im Verhältnis zum Vermögen: Ein hoher Wert deutet auf Zahlungsengpässe gegenüber öffentlichen Gläubigern hin.', 'Indique le poids des dettes envers le fisc et les organismes sociaux par rapport à l’actif : une valeur élevée signale des tensions dans les paiements aux créanciers publics.'],

            'crisisApi.creditor.payroll' => ['Retribuzioni arretrate', 'Payroll arrears', 'Lohnrückstände', 'Arriérés de salaires'],
            'crisisApi.creditor.payroll.description' => ['Retribuzioni scadute da oltre 30 giorni per un importo superiore al 50% dell’ammontare mensile delle retribuzioni.', 'Salaries overdue by more than 30 days exceeding 50% of the monthly payroll.', 'Seit mehr als 30 Tagen überfällige Löhne, die 50% der monatlichen Lohnsumme übersteigen.', 'Salaires impayés depuis plus de 30 jours dépassant 50% de la masse salariale mensuelle.'],
            'crisisApi.creditor.supplier' => ['Debiti verso fornitori scaduti', 'Supplier arrears', 'Überfällige Lieferantenverbindlichkeiten', 'Dettes fournisseurs échues'],
            'crisisApi.creditor.supplier.description' => ['Debiti verso fornitori scaduti da oltre 90 giorni per un importo superiore ai debiti verso fornitori non scaduti.', 'Supplier payables overdue by more than 90 days exceeding the non-overdue supplier payables.', 'Seit mehr als 90 Tagen überfällige Lieferantenverbindlichkeiten, die höher sind als die nicht überfälligen Lieferantenverbindlichkeiten.', 'Dettes fournisseurs échues depuis plus de 90 jours dépassant les dettes fournisseurs non échues.'],
            'crisisApi.creditor.inps' => ['INPS', 'INPS', 'INPS', 'INPS'],
            'crisisApi.creditor.inps.description' => ['Contributi INPS scaduti da oltre 90 giorni per un importo superiore al 30% di quelli dovuti nell’anno precedente e a 15.000 euro (5.000 euro in assenza di lavoratori subordinati).', 'INPS contributions overdue by more than 90 days exceeding 30% of those due in the prior year and EUR 15,000 (EUR 5,000 without employees).', 'Seit mehr als 90 Tagen überfällige INPS-Beiträge, die 30% der im Vorjahr geschuldeten Beiträge und EUR 15’000 übersteigen (EUR 5’000 ohne Arbeitnehmende).', 'Cotisations INPS échues depuis plus de 90 jours dépassant 30% de celles dues l’année précédente et 15 000 euros (5 000 euros sans salariés).'],
            'crisisApi.creditor.inail' => ['INAIL', 'INAIL', 'INAIL', 'INAIL'],
            'crisisApi.creditor.inail.description' => ['Premi INAIL scaduti da oltre 90 giorni per un importo superiore a 5.000 euro.', 'INAIL premiums overdue by more than 90 days exceeding EUR 5,000.', 'Seit mehr als 90 Tagen überfällige INAIL-Prämien über EUR 5’000.', 'Primes INAIL échues depuis plus de 90 jours dépassant 5 000 euros.'],
            'crisisApi.creditor.ade_vat' => ['Agenzia delle Entrate – IVA', 'Agenzia delle Entrate – VAT', 'Agenzia delle Entrate – Mehrwertsteuer', 'Agenzia delle Entrate – TVA'],
            'crisisApi.creditor.ade_vat.description' => ['IVA scaduta e non versata risultante dalla LIPE trimestrale, superiore a 5.000 euro e pari ad almeno il 10% del volume d’affari dell’anno precedente, oppure superiore a 20.000 euro.', 'Unpaid VAT resulting from the quarterly LIPE above EUR 5,000 and at least 10% of the prior-year turnover, or above EUR 20,000.', 'Nicht bezahlte Mehrwertsteuer aus der vierteljährlichen LIPE über EUR 5’000 und mindestens 10% des Vorjahresumsatzes oder über EUR 20’000.', 'TVA échue et non versée ressortant de la LIPE trimestrielle, supérieure à 5 000 euros et égale à au moins 10% du chiffre d’affaires de l’année précédente, ou supérieure à 20 000 euros.'],
            'crisisApi.creditor.ader' => ['Agenzia delle Entrate-Riscossione (AdER)', 'Agenzia delle Entrate-Riscossione (AdER)', 'Agenzia delle Entrate-Riscossione (AdER)', 'Agenzia delle Entrate-Riscossione (AdER)'],
            'crisisApi.creditor.ader.description' => ['Crediti affidati all’agente della riscossione e scaduti, superiori a 100.000 euro (imprese individuali), 200.000 euro (società di persone) o 500.000 euro (altre società).', 'Entrusted and expired credits with AdER above EUR 100,000 (sole proprietorships), EUR 200,000 (partnerships) or EUR 500,000 (corporations).', 'Bei der AdER zum Inkasso übergebene und verfallene Forderungen über EUR 100’000 (Einzelunternehmen), EUR 200’000 (Personengesellschaften) oder EUR 500’000 (Kapitalgesellschaften).', 'Créances confiées à l’AdER et échues supérieures à 100 000 euros (entreprises individuelles), 200 000 euros (sociétés de personnes) ou 500 000 euros (sociétés de capitaux).'],

            'crisisApi.cnc.tier.easily_achievable' => ['Risanamento facilmente conseguibile', 'Turnaround easily achievable', 'Sanierung leicht erreichbar', 'Redressement facilement réalisable'],
            'crisisApi.cnc.tier.achievable_with_measures' => ['Conseguibile con misure significative', 'Achievable with significant measures', 'Mit bedeutenden Massnahmen erreichbar', 'Réalisable moyennant des mesures importantes'],
            'crisisApi.cnc.tier.difficult' => ['Difficile', 'Difficult', 'Schwierig', 'Difficile'],
            'crisisApi.cnc.tier.not_feasible' => ['Non ragionevolmente perseguibile', 'Not reasonably feasible', 'Nicht vernünftigerweise erreichbar', 'Raisonnablement non réalisable'],
            'crisisApi.cnc.tier.unknown' => ['Non valutabile', 'Not assessable', 'Nicht beurteilbar', 'Non évaluable'],
            'crisisApi.cnc.check.ateco_configured' => ['Settore ATECO configurato', 'ATECO sector configured', 'ATECO-Sektor konfiguriert', 'Secteur ATECO configuré'],
            'crisisApi.cnc.check.financial_debt_known' => ['Debito finanziario noto', 'Financial debt known', 'Finanzschulden bekannt', 'Dette financière connue'],
            'crisisApi.cnc.check.tax_social_assessed' => ['Debito tributario e previdenziale valutato', 'Tax and social security debt assessed', 'Steuer- und Sozialversicherungsschulden beurteilt', 'Dette fiscale et sociale évaluée'],
            'crisisApi.cnc.check.public_creditor_data' => ['Arretrati verso i creditori pubblici inseriti', 'Public creditor arrears entered', 'Rückstände gegenüber öffentlichen Gläubigern erfasst', 'Arriérés envers les créanciers publics saisis'],
            'crisisApi.cnc.check.cash_flow_positive' => ['Flusso di cassa libero annuo positivo', 'Annual free cash flow positive', 'Jährlicher freier Cashflow positiv', 'Flux de trésorerie libre annuel positif'],
            'crisisApi.cnc.check.projection_covers_year' => ['La previsione di cassa copre 12 mesi', 'Cash projection covers 12 months', 'Die Liquiditätsprognose deckt 12 Monate ab', 'La prévision de trésorerie couvre 12 mois'],

            'crisisApi.ateco.A' => ['A – Agricoltura, silvicoltura e pesca', 'A – Agriculture, forestry and fishing', 'A – Land- und Forstwirtschaft, Fischerei', 'A – Agriculture, sylviculture et pêche'],
            'crisisApi.ateco.B' => ['B – Estrazione di minerali da cave e miniere', 'B – Mining and quarrying', 'B – Bergbau und Gewinnung von Steinen und Erden', 'B – Industries extractives'],
            'crisisApi.ateco.C' => ['C – Attività manifatturiere', 'C – Manufacturing', 'C – Verarbeitendes Gewerbe', 'C – Industrie manufacturière'],
            'crisisApi.ateco.D' => ['D – Fornitura di energia elettrica, gas, vapore e aria condizionata', 'D – Electricity, gas, steam and air conditioning supply', 'D – Energieversorgung (Elektrizität, Gas, Dampf und Klimatisierung)', 'D – Production et distribution d’électricité, de gaz, de vapeur et d’air conditionné'],
            'crisisApi.ateco.E' => ['E – Fornitura di acqua, reti fognarie, gestione dei rifiuti', 'E – Water supply, sewerage and waste management', 'E – Wasserversorgung, Abwasser- und Abfallentsorgung', 'E – Production et distribution d’eau, assainissement, gestion des déchets'],
            'crisisApi.ateco.F' => ['F – Costruzioni', 'F – Construction', 'F – Baugewerbe', 'F – Construction'],
            'crisisApi.ateco.G' => ['G – Commercio all’ingrosso e al dettaglio, riparazione di autoveicoli', 'G – Wholesale and retail trade, repair of motor vehicles', 'G – Gross- und Detailhandel, Reparatur von Motorfahrzeugen', 'G – Commerce de gros et de détail, réparation de véhicules automobiles'],
            'crisisApi.ateco.H' => ['H – Trasporto e magazzinaggio', 'H – Transportation and storage', 'H – Verkehr und Lagerei', 'H – Transports et entreposage'],
            'crisisApi.ateco.I' => ['I – Attività dei servizi di alloggio e di ristorazione', 'I – Accommodation and food service activities', 'I – Beherbergung und Gastronomie', 'I – Hébergement et restauration'],
            'crisisApi.ateco.J' => ['J – Servizi di informazione e comunicazione', 'J – Information and communication', 'J – Information und Kommunikation', 'J – Information et communication'],
            'crisisApi.ateco.K' => ['K – Attività finanziarie e assicurative', 'K – Financial and insurance activities', 'K – Finanz- und Versicherungsdienstleistungen', 'K – Activités financières et d’assurance'],
            'crisisApi.ateco.L' => ['L – Attività immobiliari', 'L – Real estate activities', 'L – Immobilienwesen', 'L – Activités immobilières'],
            'crisisApi.ateco.M' => ['M – Attività professionali, scientifiche e tecniche', 'M – Professional, scientific and technical activities', 'M – Freiberufliche, wissenschaftliche und technische Dienstleistungen', 'M – Activités spécialisées, scientifiques et techniques'],
            'crisisApi.ateco.N' => ['N – Noleggio, agenzie di viaggio, servizi di supporto alle imprese', 'N – Rental, travel agencies and business support services', 'N – Vermietung, Reisebüros und unternehmensnahe Dienstleistungen', 'N – Location, agences de voyage et services de soutien aux entreprises'],
            'crisisApi.ateco.O' => ['O – Amministrazione pubblica e difesa', 'O – Public administration and defence', 'O – Öffentliche Verwaltung und Landesverteidigung', 'O – Administration publique et défense'],
            'crisisApi.ateco.P' => ['P – Istruzione', 'P – Education', 'P – Erziehung und Unterricht', 'P – Enseignement'],
            'crisisApi.ateco.Q' => ['Q – Sanità e assistenza sociale', 'Q – Health and social work', 'Q – Gesundheits- und Sozialwesen', 'Q – Santé humaine et action sociale'],
            'crisisApi.ateco.R' => ['R – Attività artistiche, sportive, di intrattenimento', 'R – Arts, sports and entertainment', 'R – Kunst, Unterhaltung und Erholung', 'R – Arts, spectacles et activités récréatives'],
            'crisisApi.ateco.S' => ['S – Altre attività di servizi', 'S – Other service activities', 'S – Sonstige Dienstleistungen', 'S – Autres activités de services'],

            'crisisApi.legal_form.corporation' => ['Società di capitali', 'Corporation (capital company)', 'Kapitalgesellschaft', 'Société de capitaux'],
            'crisisApi.legal_form.partnership' => ['Società di persone', 'Partnership', 'Personengesellschaft', 'Société de personnes'],
            'crisisApi.legal_form.sole_proprietorship' => ['Impresa individuale', 'Sole proprietorship', 'Einzelunternehmen', 'Entreprise individuelle'],
            'crisisApi.jurisdiction.IT' => ['Italia – CCII / art. 2086 c.c.', 'Italy – CCII / Art. 2086 c.c.', 'Italien – CCII / Art. 2086 c.c.', 'Italie – CCII / art. 2086 c.c.'],
            'crisisApi.jurisdiction.CH' => ['Svizzera – art. 725 CO', 'Switzerland – Art. 725 CO', 'Schweiz – Art. 725 OR', 'Suisse – art. 725 CO'],

            'crisisApi.message.dashboard' => ['Cruscotto della crisi d’impresa caricato.', 'Crisis dashboard loaded.', 'Krisen-Dashboard geladen.', 'Tableau de bord de crise chargé.'],
            'crisisApi.message.computed' => ['Indicatori di allerta calcolati.', 'Early-warning indicators computed.', 'Frühwarnindikatoren berechnet.', 'Indicateurs d’alerte précoce calculés.'],
            'crisisApi.message.timeline' => ['Andamento storico degli indicatori caricato.', 'Indicator history loaded.', 'Verlauf der Indikatoren geladen.', 'Historique des indicateurs chargé.'],
            'crisisApi.message.cnc' => ['Test pratico CNC calcolato.', 'CNC practical test computed.', 'Praktischer CNC-Test berechnet.', 'Test pratique CNC calculé.'],
            'crisisApi.message.rangruecktritt' => ['Riepilogo dell’accordo di postergazione generato.', 'Subordination agreement summary generated.', 'Zusammenfassung der Rangrücktrittsvereinbarung erstellt.', 'Résumé de la convention de postposition généré.'],
            'crisisApi.message.metadata' => ['Metadati del modulo caricati.', 'Module metadata loaded.', 'Metadaten des Moduls geladen.', 'Métadonnées du module chargées.'],
            'crisisApi.message.profile' => ['Profilo di crisi caricato.', 'Crisis profile loaded.', 'Krisenprofil geladen.', 'Profil de crise chargé.'],
            'crisisApi.message.profile_saved' => ['Profilo di crisi salvato.', 'Crisis profile saved.', 'Krisenprofil gespeichert.', 'Profil de crise enregistré.'],
            'crisisApi.message.stress' => ['Stress test calcolato.', 'Stress test computed.', 'Stresstest berechnet.', 'Test de résistance calculé.'],
            'crisisApi.message.loans' => ['Finanziamenti caricati.', 'Loans loaded.', 'Darlehen geladen.', 'Prêts chargés.'],
            'crisisApi.message.loan_saved' => ['Finanziamento salvato.', 'Loan saved.', 'Darlehen gespeichert.', 'Prêt enregistré.'],
            'crisisApi.message.loan_deleted' => ['Finanziamento eliminato.', 'Loan deleted.', 'Darlehen gelöscht.', 'Prêt supprimé.'],

            'crisisApi.stress.baseline' => ['Scenario base', 'Baseline', 'Basisszenario', 'Scénario de base'],
            'crisisApi.stress.revenue_10' => ['Ricavi -10%', 'Revenue -10%', 'Umsatz -10%', 'Chiffre d’affaires -10%'],
            'crisisApi.stress.revenue_20' => ['Ricavi -20%', 'Revenue -20%', 'Umsatz -20%', 'Chiffre d’affaires -20%'],
            'crisisApi.stress.dso_30' => ['Incassi +30 giorni', 'Collections +30 days', 'Zahlungseingänge +30 Tage', 'Encaissements +30 jours'],
            'crisisApi.stress.dso_60' => ['Incassi +60 giorni', 'Collections +60 days', 'Zahlungseingänge +60 Tage', 'Encaissements +60 jours'],
            'crisisApi.stress.rate_100' => ['Tassi +100 bps', 'Rates +100 bps', 'Zinsen +100 Bp', 'Taux +100 pb'],
            'crisisApi.stress.rate_250' => ['Tassi +250 bps', 'Rates +250 bps', 'Zinsen +250 Bp', 'Taux +250 pb'],
            'crisisApi.stress.rate_500' => ['Tassi +500 bps', 'Rates +500 bps', 'Zinsen +500 Bp', 'Taux +500 pb'],
            'crisisApi.stress.combined' => ['Combinato: ricavi -20%, incassi +30 giorni, tassi +250 bps', 'Combined: revenue -20%, collections +30 days, rates +250 bps', 'Kombiniert: Umsatz -20%, Zahlungseingänge +30 Tage, Zinsen +250 Bp', 'Combiné : chiffre d’affaires -20%, encaissements +30 jours, taux +250 pb'],
            'crisisApi.stress.rate_shock' => ['Shock sui tassi di interesse', 'Interest rate shock', 'Zinsschock', 'Choc de taux d’intérêt'],
            'crisisApi.stress.one_off' => ['Esborso straordinario', 'Extraordinary outflow', 'Ausserordentlicher Mittelabfluss', 'Décaissement exceptionnel'],

            'crisisApi.dossier.title' => ['Dossier periodico sull’adeguatezza degli assetti organizzativi, amministrativi e contabili (art. 2086 c.c.)', 'Periodic dossier on the adequacy of the organisational, administrative and accounting arrangements (Art. 2086 c.c.)', 'Periodisches Dossier zur Angemessenheit der organisatorischen, administrativen und buchhalterischen Strukturen (Art. 2086 c.c.)', 'Dossier périodique sur l’adéquation des structures organisationnelles, administratives et comptables (art. 2086 c.c.)'],
            'crisisApi.dossier.as_of' => ['Situazione al {date}', 'Position at {date}', 'Stand per {date}', 'Situation au {date}'],
            'crisisApi.dossier.generated' => ['generato il {timestamp}', 'generated on {timestamp}', 'erstellt am {timestamp}', 'généré le {timestamp}'],
            'crisisApi.dossier.summary' => ['Sintesi direzionale', 'Executive summary', 'Management Summary', 'Synthèse de direction'],
            'crisisApi.dossier.overall' => ['Valutazione complessiva', 'Overall assessment', 'Gesamtbeurteilung', 'Évaluation globale'],
            'crisisApi.dossier.triggers' => ['Fattori scatenanti principali', 'Primary triggers', 'Hauptauslöser', 'Principaux déclencheurs'],
            'crisisApi.dossier.no_triggers' => ['Nessuno', 'None', 'Keine', 'Aucun'],
            'crisisApi.dossier.curve' => ['Curva dei flussi di cassa e del DSCR a 12 mesi mobili', 'Rolling 12-month cash flow and DSCR curve', 'Verlauf von Cashflow und DSCR über rollierende 12 Monate', 'Courbe des flux de trésorerie et du DSCR sur 12 mois glissants'],
            'crisisApi.dossier.indicators' => ['Indicatori di allerta (CNDCEC)', 'Early-warning indicators (CNDCEC)', 'Frühwarnindikatoren (CNDCEC)', 'Indicateurs d’alerte précoce (CNDCEC)'],
            'crisisApi.dossier.creditors' => ['Creditori pubblici qualificati – matrice di conformità all’art. 25-novies CCII', 'Qualified public creditors – Art. 25-novies CCII compliance matrix', 'Qualifizierte öffentliche Gläubiger – Compliance-Matrix nach Art. 25-novies CCII', 'Créanciers publics qualifiés – matrice de conformité à l’art. 25-novies CCII'],
            'crisisApi.dossier.cnc' => ['Test pratico CNC (D.M. 21 marzo 2023)', 'CNC practical test (D.M. 21 March 2023)', 'Praktischer CNC-Test (D.M. vom 21. März 2023)', 'Test pratique CNC (D.M. du 21 mars 2023)'],
            'crisisApi.dossier.cnc_ratio' => ['Debito / flusso di cassa libero annuo', 'Debt / annual free cash flow', 'Schulden / jährlicher freier Cashflow', 'Dette / flux de trésorerie libre annuel'],
            'crisisApi.dossier.cnc_debt' => ['Debito da ristrutturare', 'Debt to be restructured', 'Zu restrukturierende Schulden', 'Dette à restructurer'],
            'crisisApi.dossier.cnc_fcf' => ['Flusso di cassa libero annuo', 'Annual free cash flow', 'Jährlicher freier Cashflow', 'Flux de trésorerie libre annuel'],
            'crisisApi.dossier.stress' => ['Sensibilità allo stress test', 'Stress test sensitivity', 'Sensitivität im Stresstest', 'Sensibilité au test de résistance'],
            'crisisApi.dossier.signatures' => ['Verbale e firme', 'Minutes and signatures', 'Protokoll und Unterschriften', 'Procès-verbal et signatures'],
            'crisisApi.dossier.sign_board' => ['Consiglio di amministrazione', 'Board of directors', 'Verwaltungsrat', 'Conseil d’administration'],
            'crisisApi.dossier.sign_auditors' => ['Collegio sindacale', 'Board of statutory auditors', 'Revisionsstelle', 'Collège des commissaires aux comptes'],
            'crisisApi.dossier.sign_date' => ['Data', 'Date', 'Datum', 'Date'],
            'crisisApi.dossier.disclaimer' => ['Calcolato sulla base delle scritture aziendali e delle ipotesi del profilo di crisi; supporta, ma non sostituisce, la valutazione dell’organo amministrativo.', 'Computed from the company records and the assumptions of the crisis profile; it supports, and does not replace, the assessment of the administrative body.', 'Berechnet auf Grundlage der Geschäftsunterlagen und der Annahmen des Krisenprofils; die Beurteilung durch das Leitungsorgan wird dadurch unterstützt, aber nicht ersetzt.', 'Calculé à partir des écritures de l’entreprise et des hypothèses du profil de crise ; il appuie, sans la remplacer, l’appréciation de l’organe d’administration.'],

            'crisisApi.dossier.col.indicator' => ['Indicatore', 'Indicator', 'Indikator', 'Indicateur'],
            'crisisApi.dossier.col.value' => ['Valore', 'Value', 'Wert', 'Valeur'],
            'crisisApi.dossier.col.numerator' => ['Numeratore', 'Numerator', 'Zähler', 'Numérateur'],
            'crisisApi.dossier.col.denominator' => ['Denominatore', 'Denominator', 'Nenner', 'Dénominateur'],
            'crisisApi.dossier.col.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'crisisApi.dossier.col.month' => ['Mese', 'Month', 'Monat', 'Mois'],
            'crisisApi.dossier.col.inflows' => ['Entrate', 'Inflows', 'Einnahmen', 'Encaissements'],
            'crisisApi.dossier.col.outflows' => ['Uscite', 'Outflows', 'Ausgaben', 'Décaissements'],
            'crisisApi.dossier.col.debt_service' => ['Servizio del debito', 'Debt service', 'Schuldendienst', 'Service de la dette'],
            'crisisApi.dossier.col.cash' => ['Cassa finale', 'Closing cash', 'Schlussbestand Liquidität', 'Trésorerie de clôture'],
            'crisisApi.dossier.col.dscr_treasury' => ['DSCR tesoreria', 'DSCR treasury', 'DSCR Liquidität', 'DSCR trésorerie'],
            'crisisApi.dossier.col.dscr_operating' => ['DSCR operativo', 'DSCR operating', 'DSCR operativ', 'DSCR opérationnel'],
            'crisisApi.dossier.col.actual' => ['Effettivo', 'Actual', 'Ist', 'Réel'],
            'crisisApi.dossier.col.threshold' => ['Soglia', 'Threshold', 'Schwellenwert', 'Seuil'],
            'crisisApi.dossier.col.rule' => ['Regola', 'Rule', 'Regel', 'Règle'],
            'crisisApi.dossier.col.creditor' => ['Creditore', 'Creditor', 'Gläubiger', 'Créancier'],
            'crisisApi.dossier.col.measured' => ['Misurato', 'Measured', 'Gemessen', 'Mesuré'],
            'crisisApi.dossier.col.limit' => ['Limite', 'Limit', 'Grenzwert', 'Limite'],
            'crisisApi.dossier.col.progress' => ['Avanzamento', 'Progress', 'Fortschritt', 'Progression'],
            'crisisApi.dossier.col.scenario' => ['Scenario', 'Scenario', 'Szenario', 'Scénario'],
            'crisisApi.dossier.col.dscr_t6' => ['DSCR tesoreria 6 mesi', 'DSCR treasury 6m', 'DSCR Liquidität 6 Mt.', 'DSCR trésorerie 6 mois'],
            'crisisApi.dossier.col.dscr_t12' => ['DSCR tesoreria 12 mesi', 'DSCR treasury 12m', 'DSCR Liquidität 12 Mt.', 'DSCR trésorerie 12 mois'],
            'crisisApi.dossier.col.dscr_o12' => ['DSCR operativo 12 mesi', 'DSCR operating 12m', 'DSCR operativ 12 Mt.', 'DSCR opérationnel 12 mois'],
            'crisisApi.dossier.col.gap' => ['Fabbisogno di liquidità', 'Liquidity gap', 'Liquiditätslücke', 'Déficit de liquidité'],

            'crisisApi.agreement.title' => ['Accordo di postergazione (Rangrücktrittsvereinbarung) – art. 725b CO', 'Subordination agreement (Rangrücktrittsvereinbarung) – Art. 725b CO', 'Rangrücktrittsvereinbarung – Art. 725b OR', 'Convention de postposition (Rangrücktrittsvereinbarung) – art. 725b CO'],
            'crisisApi.agreement.preamble' => ['Poiché {company} è o potrebbe diventare sovraindebitata ai sensi dell’art. 725b CO, le parti convengono la seguente postergazione.', 'Because {company} is or may become over-indebted within the meaning of Art. 725b CO, the parties agree on the following subordination.', 'Da {company} im Sinne von Art. 725b OR überschuldet ist oder überschuldet werden könnte, vereinbaren die Parteien den nachstehenden Rangrücktritt.', 'Comme {company} est ou pourrait devenir surendettée au sens de l’art. 725b CO, les parties conviennent de la postposition suivante.'],
            'crisisApi.agreement.parties' => ['Debitrice: {company}. Creditore: {creditor}. Accordo del {agreement_date}, con efficacia dal {effective_from}.', 'Debtor: {company}. Creditor: {creditor}. Agreement dated {agreement_date}, effective from {effective_from}.', 'Schuldnerin: {company}. Gläubiger: {creditor}. Vereinbarung vom {agreement_date}, wirksam ab {effective_from}.', 'Débitrice : {company}. Créancier : {creditor}. Convention du {agreement_date}, en vigueur dès le {effective_from}.'],
            'crisisApi.agreement.clause.subordination' => ['Il creditore posterga il proprio credito di CHF/EUR {principal} (capitale) rispetto a tutti gli altri creditori della società, nel senso che il credito non è computato come debito nel bilancio di sovraindebitamento.', 'The creditor subordinates its claim of CHF/EUR {principal} (principal) to all other creditors of the company, in the sense that the claim is not to be counted as a liability in the over-indebtedness balance sheet.', 'Der Gläubiger tritt mit seiner Forderung von CHF/EUR {principal} (Kapital) im Rang hinter alle anderen Gläubiger der Gesellschaft zurück, in dem Sinne, dass die Forderung in der Überschuldungsbilanz nicht als Verbindlichkeit zu berücksichtigen ist.', 'Le créancier postpose sa créance de CHF/EUR {principal} (capital) à celle de tous les autres créanciers de la société, en ce sens que la créance n’est pas comptabilisée comme dette dans le bilan de surendettement.'],
            'crisisApi.agreement.clause.interest' => ['Interessi al {rate}% annuo; sono postergati anche gli interessi maturati {accrued} e gli interessi contrattuali fino alla fine dell’orizzonte {future}; credito postergato complessivo {claim}.', 'Interest at {rate}% p.a.; accrued interest {accrued} and contractual interest until the end of the horizon {future} are subordinated as well; total subordinated claim {claim}.', 'Zins von {rate}% p.a.; aufgelaufene Zinsen {accrued} sowie vertragliche Zinsen bis zum Ende des Planungshorizonts {future} sind ebenfalls nachrangig; nachrangige Gesamtforderung {claim}.', 'Intérêts de {rate}% par an ; les intérêts courus {accrued} et les intérêts contractuels jusqu’à la fin de l’horizon {future} sont également postposés ; créance postposée totale {claim}.'],
            'crisisApi.agreement.clause.repayment' => ['Il rimborso del capitale e il pagamento degli interessi sono esclusi finché e nella misura in cui la società è sovraindebitata; i pagamenti sono ammessi solo con capitale proprio libero o dopo il soddisfacimento degli altri creditori.', 'Repayment of principal and payment of interest are excluded as long as and to the extent that the company is over-indebted; payments are allowed only from free equity or after the other creditors are satisfied.', 'Rückzahlung von Kapital und Zahlung von Zinsen sind ausgeschlossen, solange und soweit die Gesellschaft überschuldet ist; Zahlungen sind nur aus frei verwendbarem Eigenkapital oder nach Befriedigung der übrigen Gläubiger zulässig.', 'Le remboursement du capital et le paiement des intérêts sont exclus aussi longtemps et dans la mesure où la société est surendettée ; les paiements ne sont admis que sur des fonds propres librement disponibles ou après désintéressement des autres créanciers.'],
            'crisisApi.agreement.clause.duration' => ['La postergazione vale dal {effective_from} fino al {deferral_end}.', 'The subordination applies from {effective_from} until {deferral_end}.', 'Der Rangrücktritt gilt vom {effective_from} bis zum {deferral_end}.', 'La postposition s’applique du {effective_from} au {deferral_end}.'],
            'crisisApi.agreement.clause.insolvency' => ['In caso di fallimento, liquidazione o procedura concordataria, il credito è collocato dopo tutti i crediti non postergati.', 'In bankruptcy, liquidation or composition proceedings the claim ranks after all non-subordinated claims.', 'Im Konkurs, in der Liquidation oder im Nachlassverfahren steht die Forderung im Rang nach allen nicht nachrangigen Forderungen.', 'En cas de faillite, de liquidation ou de procédure concordataire, la créance prend rang après toutes les créances non postposées.'],
            'crisisApi.agreement.clause.disclosure' => ['L’accordo va conservato con la contabilità e mostrato all’ufficio di revisione; non è necessaria la notifica al giudice finché la postergazione copre il sovraindebitamento.', 'The agreement is to be kept with the accounting records and shown to the auditors; a notification of the court is not required while the subordination covers the over-indebtedness.', 'Die Vereinbarung ist bei den Buchhaltungsunterlagen aufzubewahren und der Revisionsstelle vorzulegen; eine Benachrichtigung des Gerichts ist nicht erforderlich, solange der Rangrücktritt die Überschuldung abdeckt.', 'La convention doit être conservée avec les pièces comptables et présentée à l’organe de révision ; l’avis au juge n’est pas nécessaire tant que la postposition couvre le surendettement.'],
            'crisisApi.agreement.open_ended' => ['fino al superamento del sovraindebitamento e alla liberazione scritta del creditore', 'until the over-indebtedness is cured and the creditor is released in writing', 'bis zur Behebung der Überschuldung und zur schriftlichen Entlassung des Gläubigers', 'jusqu’à la résorption du surendettement et à la libération écrite du créancier'],
            'crisisApi.agreement.sign_company' => ['Firma per la società', 'Signature for the company', 'Unterschrift für die Gesellschaft', 'Signature pour la société'],
            'crisisApi.agreement.sign_creditor' => ['Firma del creditore', 'Signature of the creditor', 'Unterschrift des Gläubigers', 'Signature du créancier'],
            'crisisApi.agreement.disclaimer' => ['Riepilogo generato automaticamente; il testo vincolante deve essere verificato da un legale prima della firma.', 'Summary generated automatically; the binding wording must be reviewed by a lawyer before signature.', 'Automatisch erstellte Zusammenfassung; der verbindliche Wortlaut ist vor der Unterzeichnung juristisch zu prüfen.', 'Résumé généré automatiquement ; le texte contraignant doit être vérifié par un juriste avant la signature.'],
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
