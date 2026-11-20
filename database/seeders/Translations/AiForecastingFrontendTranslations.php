<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the AI cash flow and demand forecasting module (Mod 094): fan chart, what-if panel, customer risk tiers,
 * CH/IT statutory event badges, demand forecast and replenishment grid. Each entry is [it, en, de, fr]. Placeholders are
 * written {name}.
 */
final class AiForecastingFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'AI_FORECAST.NAV' => ['Previsioni AI', 'AI forecasting', 'KI-Prognosen', 'Prévisions IA'],
            'AI_FORECAST.TITLE' => ['Previsioni AI di cassa e domanda', 'AI cash flow and demand forecasting', 'KI-Liquiditäts- und Bedarfsprognose', 'Prévisions IA de trésorerie et de demande'],
            'AI_FORECAST.SUBTITLE' => [
                'Liquidità giorno per giorno con bande P10/P50/P90, scadenze fiscali CH/IT e riordino consigliato per articolo.',
                'Day-by-day liquidity with P10/P50/P90 bands, CH/IT tax deadlines and recommended reorder points per SKU.',
                'Tagesgenaue Liquidität mit P10/P50/P90-Bändern, CH/IT-Steuerterminen und empfohlenen Meldebeständen je Artikel.',
                'Trésorerie au jour le jour avec bandes P10/P50/P90, échéances fiscales CH/IT et points de commande recommandés par article.',
            ],
            'AI_FORECAST.SECTIONS' => ['Sezioni delle previsioni AI', 'AI forecasting sections', 'Bereiche der KI-Prognosen', 'Sections des prévisions IA'],
            'AI_FORECAST.TAB_LIQUIDITY' => ['Liquidità', 'Liquidity', 'Liquidität', 'Trésorerie'],
            'AI_FORECAST.TAB_DEMAND' => ['Domanda e riordino', 'Demand and replenishment', 'Bedarf und Nachschub', 'Demande et réapprovisionnement'],

            'AI_FORECAST.HORIZON_13_WEEKS' => ['13 settimane', '13 weeks', '13 Wochen', '13 semaines'],
            'AI_FORECAST.HORIZON_12_MONTHS' => ['12 mesi', '12 months', '12 Monate', '12 mois'],
            'AI_FORECAST.WHAT_IF' => ['Simulazione what-if', 'What-if simulation', 'What-if-Simulation', 'Simulation what-if'],
            'AI_FORECAST.WHAT_IF_TITLE' => ['Scenario what-if', 'What-if scenario', 'What-if-Szenario', 'Scénario what-if'],
            'AI_FORECAST.REFRESH' => ['Aggiorna previsione', 'Refresh forecast', 'Prognose aktualisieren', 'Actualiser la prévision'],
            'AI_FORECAST.REFRESHED' => ['Previsione aggiornata', 'Forecast refreshed', 'Prognose aktualisiert', 'Prévision actualisée'],
            'AI_FORECAST.REFRESH_ERROR' => ['Impossibile aggiornare la previsione', 'The forecast could not be refreshed', 'Die Prognose konnte nicht aktualisiert werden', 'La prévision n’a pas pu être actualisée'],
            'AI_FORECAST.LOAD_ERROR' => ['Impossibile caricare le previsioni', 'The forecasts could not be loaded', 'Die Prognosen konnten nicht geladen werden', 'Les prévisions n’ont pas pu être chargées'],
            'AI_FORECAST.NO_RUN' => ['Nessuna simulazione di liquidità per questo orizzonte', 'There is no liquidity simulation for this horizon yet', 'Für diesen Horizont gibt es noch keine Liquiditätssimulation', 'Aucune simulation de trésorerie pour cet horizon'],
            'AI_FORECAST.RUN_SIMULATION' => ['Esegui simulazione', 'Run simulation', 'Simulation starten', 'Lancer la simulation'],
            'AI_FORECAST.NONE' => ['Nessuno', 'None', 'Keine', 'Aucun'],

            'AI_FORECAST.P10' => ['P10 (pessimistico)', 'P10 (pessimistic)', 'P10 (pessimistisch)', 'P10 (pessimiste)'],
            'AI_FORECAST.P50' => ['P50 (atteso)', 'P50 (expected)', 'P50 (erwartet)', 'P50 (attendu)'],
            'AI_FORECAST.P90' => ['P90 (ottimistico)', 'P90 (optimistic)', 'P90 (optimistisch)', 'P90 (optimiste)'],
            'AI_FORECAST.FAN_CHART_TITLE' => ['Saldo di cassa previsto', 'Projected cash balance', 'Prognostizierter Kassenbestand', 'Solde de trésorerie prévu'],
            'AI_FORECAST.KPI_STARTING_CASH' => ['Cassa iniziale', 'Starting cash', 'Anfangsbestand', 'Trésorerie initiale'],
            'AI_FORECAST.KPI_TROUGH' => ['Minimo di cassa atteso', 'Expected cash trough', 'Erwarteter Tiefpunkt', 'Creux de trésorerie attendu'],
            'AI_FORECAST.KPI_RUNWAY' => ['Autonomia di cassa', 'Cash runway', 'Liquiditätsreichweite', 'Autonomie de trésorerie'],
            'AI_FORECAST.KPI_PREVIEW_DELTA' => ['Variazione del minimo (what-if)', 'Trough change (what-if)', 'Änderung des Tiefpunkts (What-if)', 'Variation du creux (what-if)'],
            'AI_FORECAST.RUNWAY_SAFE' => ['Oltre l’orizzonte', 'Beyond the horizon', 'Über den Horizont hinaus', 'Au-delà de l’horizon'],
            'AI_FORECAST.RUNWAY_DAYS' => ['{days} giorni', '{days} days', '{days} Tage', '{days} jours'],
            'AI_FORECAST.TROUGH_ALERT' => ['Allarme: la cassa prevista scende sotto zero', 'Alert: the expected cash balance drops below zero', 'Warnung: Der erwartete Kassenbestand fällt unter null', 'Alerte : la trésorerie prévue passe sous zéro'],
            'AI_FORECAST.PREVIEW_BANNER' => ['Anteprima di uno scenario what-if, non salvata.', 'Preview of a what-if scenario, not saved.', 'Vorschau eines What-if-Szenarios, nicht gespeichert.', 'Aperçu d’un scénario what-if, non enregistré.'],
            'AI_FORECAST.PREVIEW_RUNNING' => ['Calcolo dello scenario…', 'Calculating the scenario…', 'Szenario wird berechnet …', 'Calcul du scénario…'],
            'AI_FORECAST.PREVIEW_ERROR' => ['Impossibile calcolare lo scenario', 'The scenario could not be calculated', 'Das Szenario konnte nicht berechnet werden', 'Le scénario n’a pas pu être calculé'],
            'AI_FORECAST.PREVIEW_DELTA_HINT' => ['Variazione del minimo di cassa:', 'Change of the cash trough:', 'Änderung des Kassentiefpunkts:', 'Variation du creux de trésorerie :'],
            'AI_FORECAST.RESET' => ['Ripristina', 'Reset', 'Zurücksetzen', 'Réinitialiser'],
            'AI_FORECAST.SLIDER_DELAY' => ['Variazione del ritardo medio di pagamento dei clienti (giorni)', 'Average customer payment delay shift (days)', 'Verschiebung der durchschnittlichen Kundenzahlungsverzögerung (Tage)', 'Variation du retard de paiement moyen des clients (jours)'],
            'AI_FORECAST.SLIDER_SALES' => ['Variazione del volume di vendita previsto (%)', 'Projected sales volume variance (%)', 'Abweichung des geplanten Verkaufsvolumens (%)', 'Variation du volume de ventes prévu (%)'],
            'AI_FORECAST.SLIDER_SUPPLIERS' => ['Estensione dei termini di pagamento ai fornitori (giorni)', 'Supplier payment terms extension (days)', 'Verlängerung der Lieferantenzahlungsziele (Tage)', 'Prolongation des délais de paiement fournisseurs (jours)'],

            'AI_FORECAST.DRIVER_OVERDUE' => ['Fatture scadute principali', 'Top overdue invoices', 'Größte überfällige Rechnungen', 'Principales factures en retard'],
            'AI_FORECAST.DRIVER_STATUTORY' => ['Principali scadenze fiscali', 'Major upcoming tax liabilities', 'Größte anstehende Steuerverbindlichkeiten', 'Principales échéances fiscales à venir'],
            'AI_FORECAST.DRIVER_DELAYS' => ['Clienti con maggior impatto del ritardo', 'High-impact customer delays', 'Kunden mit größter Verzögerungswirkung', 'Clients au retard le plus impactant'],

            'AI_FORECAST.RISK_TABLE_TITLE' => ['Rischio di pagamento dei clienti', 'Customer payment risk', 'Zahlungsrisiko der Kunden', 'Risque de paiement des clients'],
            'AI_FORECAST.COL_CUSTOMER' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'AI_FORECAST.COL_AVG_DAYS_LATE' => ['Ritardo medio (giorni)', 'Average days late', 'Ø Tage Verzug', 'Retard moyen (jours)'],
            'AI_FORECAST.COL_ON_TIME' => ['Probabilità di puntualità', 'On-time probability', 'Pünktlichkeitswahrscheinlichkeit', 'Probabilité de ponctualité'],
            'AI_FORECAST.COL_RISK' => ['Rischio', 'Risk', 'Risiko', 'Risque'],
            'AI_FORECAST.COL_COLLECTION' => ['Incasso previsto delle fatture aperte', 'Predicted collection of open invoices', 'Prognostizierter Zahlungseingang offener Rechnungen', 'Encaissement prévu des factures ouvertes'],
            'AI_FORECAST.TIER_LOW' => ['Basso', 'Low', 'Niedrig', 'Faible'],
            'AI_FORECAST.TIER_MODERATE' => ['Moderato', 'Moderate', 'Mittel', 'Modéré'],
            'AI_FORECAST.TIER_HIGH' => ['Alto', 'High', 'Hoch', 'Élevé'],
            'AI_FORECAST.TIER_CRITICAL' => ['Critico', 'Critical', 'Kritisch', 'Critique'],

            'AI_FORECAST.EVENT_F24' => ['F24', 'F24', 'F24', 'F24'],
            'AI_FORECAST.EVENT_LIPE_Q1' => ['LIPE 1° trim.', 'LIPE Q1', 'LIPE Q1', 'LIPE T1'],
            'AI_FORECAST.EVENT_LIPE_Q2' => ['LIPE 2° trim.', 'LIPE Q2', 'LIPE Q2', 'LIPE T2'],
            'AI_FORECAST.EVENT_LIPE_Q3' => ['LIPE 3° trim.', 'LIPE Q3', 'LIPE Q3', 'LIPE T3'],
            'AI_FORECAST.EVENT_LIPE_Q4' => ['Saldo IVA annuale', 'Annual VAT balance', 'IVA-Jahressaldo', 'Solde TVA annuel'],
            'AI_FORECAST.EVENT_IVA_ADVANCE' => ['Acconto IVA', 'VAT advance', 'IVA-Akontozahlung', 'Acompte TVA'],
            'AI_FORECAST.EVENT_TAX_ADVANCE_JUN' => ['Acconto imposte (giugno)', 'Tax advance (June)', 'Steuervorauszahlung (Juni)', 'Acompte d’impôt (juin)'],
            'AI_FORECAST.EVENT_TAX_ADVANCE_NOV' => ['Acconto imposte (novembre)', 'Tax advance (November)', 'Steuervorauszahlung (November)', 'Acompte d’impôt (novembre)'],
            'AI_FORECAST.EVENT_MWST_Q1' => ['IVA CH 1° trim.', 'MWST Q1', 'MWST Q1', 'TVA CH T1'],
            'AI_FORECAST.EVENT_MWST_Q2' => ['IVA CH 2° trim.', 'MWST Q2', 'MWST Q2', 'TVA CH T2'],
            'AI_FORECAST.EVENT_MWST_Q3' => ['IVA CH 3° trim.', 'MWST Q3', 'MWST Q3', 'TVA CH T3'],
            'AI_FORECAST.EVENT_MWST_Q4' => ['IVA CH 4° trim.', 'MWST Q4', 'MWST Q4', 'TVA CH T4'],
            'AI_FORECAST.EVENT_AVS_LPP' => ['AVS/LPP', 'AHV/BVG', 'AHV/BVG', 'AVS/LPP'],
            'AI_FORECAST.EVENT_AVS_LPP_RECONCILIATION' => ['Conguaglio AVS/LPP', 'AHV/BVG reconciliation', 'AHV/BVG-Abrechnung', 'Décompte AVS/LPP'],
            'AI_FORECAST.EVENT_SALARY_13TH' => ['Tredicesima', '13th salary', '13. Monatslohn', '13e salaire'],
            'AI_FORECAST.EVENT_SALARY_14TH' => ['Quattordicesima', '14th salary', '14. Monatslohn', '14e salaire'],

            'AI_FORECAST.FILTER_WAREHOUSE' => ['Magazzino', 'Warehouse', 'Lager', 'Entrepôt'],
            'AI_FORECAST.FILTER_SEARCH' => ['Cerca articolo o categoria', 'Search product or category', 'Artikel oder Kategorie suchen', 'Rechercher un article ou une catégorie'],
            'AI_FORECAST.FILTER_PERIOD' => ['Periodo di previsione', 'Forecast period', 'Prognosezeitraum', 'Période de prévision'],
            'AI_FORECAST.PERIOD_MONTHS' => ['{months} mesi', '{months} months', '{months} Monate', '{months} mois'],
            'AI_FORECAST.GENERATE' => ['Genera previsioni', 'Generate forecasts', 'Prognosen erstellen', 'Générer les prévisions'],
            'AI_FORECAST.GENERATED' => ['Previsioni generate', 'Forecasts generated', 'Prognosen erstellt', 'Prévisions générées'],
            'AI_FORECAST.GENERATE_ERROR' => ['Impossibile generare le previsioni', 'The forecasts could not be generated', 'Die Prognosen konnten nicht erstellt werden', 'Les prévisions n’ont pas pu être générées'],
            'AI_FORECAST.KPI_MAPE' => ['MAPE (errore percentuale medio assoluto)', 'MAPE (mean absolute percentage error)', 'MAPE (mittlerer absoluter prozentualer Fehler)', 'MAPE (erreur moyenne en pourcentage absolu)'],
            'AI_FORECAST.KPI_WAPE' => ['WAPE (errore percentuale assoluto ponderato)', 'WAPE (weighted absolute percentage error)', 'WAPE (gewichteter absoluter prozentualer Fehler)', 'WAPE (erreur en pourcentage absolu pondérée)'],
            'AI_FORECAST.KPI_SAMPLES' => ['Periodi chiusi valutati', 'Closed periods evaluated', 'Ausgewertete abgeschlossene Perioden', 'Périodes clôturées évaluées'],
            'AI_FORECAST.CHART_TITLE' => ['Venduto storico e domanda prevista', 'Historical sales vs forecast demand', 'Historischer Absatz und prognostizierter Bedarf', 'Ventes historiques et demande prévue'],
            'AI_FORECAST.SERIES_ACTUAL' => ['Venduto', 'Actual sales', 'Ist-Absatz', 'Ventes réelles'],
            'AI_FORECAST.SERIES_FORECAST' => ['Previsione', 'Forecast', 'Prognose', 'Prévision'],
            'AI_FORECAST.SERIES_LOWER' => ['Limite inferiore', 'Lower bound', 'Untere Grenze', 'Limite inférieure'],
            'AI_FORECAST.SERIES_UPPER' => ['Limite superiore', 'Upper bound', 'Obere Grenze', 'Limite supérieure'],
            'AI_FORECAST.GRID_TITLE' => ['Riordino consigliato', 'Recommended replenishment', 'Empfohlener Nachschub', 'Réapprovisionnement recommandé'],
            'AI_FORECAST.COL_SKU' => ['Articolo', 'SKU', 'Artikel', 'Article'],
            'AI_FORECAST.COL_WAREHOUSE' => ['Magazzino', 'Warehouse', 'Lager', 'Entrepôt'],
            'AI_FORECAST.COL_STOCK' => ['Giacenza', 'Current stock', 'Aktueller Bestand', 'Stock actuel'],
            'AI_FORECAST.COL_ROP' => ['Punto di riordino: attuale → AI', 'Reorder point: current → AI', 'Meldebestand: aktuell → KI', 'Point de commande : actuel → IA'],
            'AI_FORECAST.COL_SS' => ['Scorta di sicurezza: attuale → AI', 'Safety stock: current → AI', 'Sicherheitsbestand: aktuell → KI', 'Stock de sécurité : actuel → IA'],
            'AI_FORECAST.COL_SEASONALITY' => ['Indice di stagionalità', 'Seasonality index', 'Saisonindex', 'Indice de saisonnalité'],
            'AI_FORECAST.APPLY_ONE' => ['Applica punto di riordino a WMS/MPS', 'Apply reorder point to WMS/MPS', 'Meldebestand auf WMS/MPS anwenden', 'Appliquer le point de commande au WMS/MPS'],
            'AI_FORECAST.APPLY_CONFIRM' => ['Aggiornare punto di riordino e scorta di sicurezza in magazzino e pianificazione?', 'Update the reorder point and safety stock in the warehouse and planning data?', 'Meldebestand und Sicherheitsbestand in Lager- und Planungsdaten aktualisieren?', 'Mettre à jour le point de commande et le stock de sécurité dans l’entrepôt et la planification ?'],
            'AI_FORECAST.APPLY_SELECTED' => ['Applica la selezione a WMS/MPS', 'Apply selection to WMS/MPS', 'Auswahl auf WMS/MPS anwenden', 'Appliquer la sélection au WMS/MPS'],
            'AI_FORECAST.APPLY_BULK_CONFIRM' => ['Applicare le soglie consigliate a tutti gli articoli selezionati?', 'Apply the recommended thresholds to every selected SKU?', 'Die empfohlenen Schwellen auf alle ausgewählten Artikel anwenden?', 'Appliquer les seuils recommandés à tous les articles sélectionnés ?'],
            'AI_FORECAST.APPLIED' => ['Applicato', 'Applied', 'Angewendet', 'Appliqué'],
            'AI_FORECAST.APPLIED_COUNT' => ['{count} articoli aggiornati in WMS/MPS', '{count} SKUs updated in WMS/MPS', '{count} Artikel in WMS/MPS aktualisiert', '{count} articles mis à jour dans le WMS/MPS'],
            'AI_FORECAST.APPLY_ERROR' => ['Impossibile applicare le soglie', 'The thresholds could not be applied', 'Die Schwellen konnten nicht angewendet werden', 'Les seuils n’ont pas pu être appliqués'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = match ($locale) {
            'it' => 0,
            'de' => 2,
            'fr' => 3,
            default => 1,
        };

        return array_map(static fn (array $values): string => $values[$index], self::definitions());
    }
}
