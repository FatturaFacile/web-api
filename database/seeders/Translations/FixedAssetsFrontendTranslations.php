<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings for Fixed Assets & Parallel CH/IT Depreciation (Mod 020 / #167) across it, en, de, fr.
 * Each entry is [it, en, de, fr]; RegionalTranslations derives it-CH, de-CH, fr-CH, it-IT.
 */
final class FixedAssetsFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.ASSET_DEPRECIATION' => ['Ammortamenti & Chiusura', 'Depreciation & Closing', 'Abschreibungen & Abschluss', 'Amortissements & Clôture'],
            'NAV.ASSET_REGISTER' => ['Libro Cespiti Ufficiale', 'Official Asset Register', 'Anlageverzeichnis', 'Registre des Immobilisations'],
            'PAGE_TITLE.ASSET_DEPRECIATION' => ['Ammortamenti Paralleli & Scritture di Chiusura', 'Parallel Depreciation & Year-End GL Posting', 'Parallele Abschreibungen & Jahresabschluss', 'Amortissements Parallèles & Clôture'],
            'PAGE_TITLE.ASSET_REGISTER' => ['Libro Cespiti Ammortizzabili (Art. 16 DPR 600/73 & Art. 958c CO)', 'Fixed Asset Register (Art. 16 DPR 600/73 & Art. 958c CO)', 'Anlageverzeichnis (Art. 958c OR & Art. 16 DPR 600/73)', 'Registre des Immobilisations (Art. 958c CO & Art. 16 DPR 600/73)'],

            'fixedAssets.actions.acquireFromInvoice' => ['Da Fattura Fornitore', 'From Vendor Invoice', 'Aus Lieferantenrechnung', 'Depuis Facture Fournisseur'],
            'fixedAssets.actions.yearEndBatch' => ['Ammortamenti Fine Anno', 'Year-End Depreciation', 'Jahresabschluss-AfA', 'Clôture Amortissements'],
            'fixedAssets.actions.officialRegister' => ['Libro Cespiti', 'Asset Register', 'Anlageverzeichnis', 'Registre Cespiti'],
            'fixedAssets.actions.recordImprovement' => ['Spese Incrementative', 'Capital Improvement', 'Werterhöhung aktivieren', 'Amélioration immobilisée'],
            'fixedAssets.actions.recordValuation' => ['Svalutazione / Rivalutazione', 'Impairment / Revaluation', 'Wertminderung / Neubewertung', 'Dépréciation / Réévaluation'],
            'fixedAssets.actions.disposeAsset' => ['Cessione / Dismissione', 'Sell / Scrap / Dispose', 'Veräusserung / Abgang', 'Cession / Mise au rebut'],
            'fixedAssets.actions.configureFiscalBook' => ['Configura Libro Fiscale CH/IT', 'Configure CH/IT Fiscal Book', 'Steuerbuch CH/IT konfigurieren', 'Configurer Livre Fiscal CH/IT'],
            'fixedAssets.actions.previewDisposal' => ['Anteprima Plus/Minusvalenza', 'Preview Gain/Loss & GL', 'Vorschau Gewinn/Verlust', 'Aperçu Plus/Moins-value'],
            'fixedAssets.actions.postToGl' => ['Contabilizza a Giornale', 'Post to GL', 'In Hauptbuch buchen', 'Comptabiliser au Journal'],
            'fixedAssets.actions.simulate' => ['Simula Anteprima', 'Simulate Preview', 'Vorschau simulieren', 'Simuler Aperçu'],
            'fixedAssets.actions.executeBatch' => ['Esegui & Contabilizza Anno', 'Execute & Post Year-End', 'Jahreslauf ausführen & buchen', 'Exécuter & Comptabiliser'],
            'fixedAssets.actions.downloadPdf' => ['Stampa PDF Ufficiale', 'Official PDF Print', 'Offizielles PDF drucken', 'Imprimer PDF Officiel'],
            'fixedAssets.actions.printBrowser' => ['Stampa Pagina', 'Print View', 'Seite drucken', 'Imprimer la page'],
            'fixedAssets.actions.exportCsv' => ['Esporta CSV', 'Export CSV', 'CSV exportieren', 'Exporter CSV'],

            'fixedAssets.fields.location' => ['Ubicazione / Sede', 'Location / Site', 'Standort', 'Emplacement'],
            'fixedAssets.fields.costCenter' => ['Centro di Costo', 'Cost Center', 'Kostenstelle', 'Centre de coûts'],
            'fixedAssets.fields.supplier' => ['Fornitore', 'Supplier', 'Lieferant', 'Fournisseur'],
            'fixedAssets.fields.purchaseInvoiceNumber' => ['N. Fattura Acquisto', 'Purchase Invoice No.', 'Einkaufsrechnungs-Nr.', 'N° Facture Achat'],
            'fixedAssets.fields.acquisitionDate' => ['Data Acquisto', 'Acquisition Date', 'Anschaffungsdatum', 'Date d\'acquisition'],
            'fixedAssets.fields.inServiceDate' => ['Data Entrata in Funzione', 'In-Service Date', 'Inbetriebnahmedatum', 'Date de mise en service'],
            'fixedAssets.fields.proRataConvention' => ['Convenzione Pro-Rata', 'Pro-Rata Convention', 'Pro-Rata-Regelung', 'Convention Pro-Rata'],
            'fixedAssets.fields.fiscalJurisdiction' => ['Giurisdizione Fiscale', 'Fiscal Jurisdiction', 'Steuerhoheit', 'Juridiction Fiscale'],
            'fixedAssets.fields.fiscalRatePreset' => ['Tabella Fiscale AFC / D.M. 1988', 'AFC / D.M. 1988 Rate Table', 'ESTV / D.M. 1988 Satztabelle', 'Barème AFC / D.M. 1988'],
            'fixedAssets.fields.immediateExpensing' => ['Deduzione immediata (CH Sofortabschreibung)', 'Immediate Expensing (CH Sofortabschreibung)', 'Sofortabschreibung (ESTV CH)', 'Amortissement immédiat (AFC CH)'],
            'fixedAssets.fields.incentiveRegime' => ['Agevolazione Transizione 4.0 / 5.0', 'Transition 4.0 / 5.0 Incentive', 'Förderung Transition 4.0 / 5.0', 'Incitation Transition 4.0 / 5.0'],
            'fixedAssets.fields.incentiveRate' => ['Aliquota Agevolazione (%)', 'Incentive Rate (%)', 'Fördersatz (%)', 'Taux d\'incitation (%)'],
            'fixedAssets.fields.incentiveYears' => ['Anni Quote Credito', 'Installment Years', 'Verteilungsjahre', 'Années d\'étalement'],
            'fixedAssets.fields.interconnectionDate' => ['Data Interconnessione', 'Interconnection Date', 'Vernetzungsdatum', 'Date d\'interconnexion'],
            'fixedAssets.fields.extendsLifeMonths' => ['Estensione Vita Utile (mesi)', 'Extend Useful Life (months)', 'Nutzungsdauerverlängerung (Monate)', 'Extension durée de vie (mois)'],
            'fixedAssets.fields.affectsTaxBook' => ['Applica anche al Libro Fiscale', 'Apply to Fiscal Book too', 'Auch auf Steuerbuch anwenden', 'Appliquer aussi au livre fiscal'],
            'fixedAssets.fields.disposalType' => ['Tipo Dismissione', 'Disposal Type', 'Abgangsart', 'Type de sortie'],
            'fixedAssets.fields.disposalDate' => ['Data Cessione / Dismissione', 'Disposal Date', 'Abgangsdatum', 'Date de cession'],
            'fixedAssets.fields.disposedPercentage' => ['Quota Dismessa (%)', 'Disposed Percentage (%)', 'Abgangsanteil (%)', 'Pourcentage cédé (%)'],
            'fixedAssets.fields.proceedsAmount' => ['Corrispettivo di Vendita', 'Sale Proceeds', 'Verkaufserlös', 'Prix de cession'],
            'fixedAssets.fields.disposalCosts' => ['Oneri Accessori Dismissione', 'Disposal Costs', 'Abgangskosten', 'Frais de cession'],
            'fixedAssets.fields.disposalDocNumber' => ['N. Fattura / Verbale Cessione', 'Sales Invoice / Disposal Doc.', 'Verkaufsrechnung / Beleg-Nr.', 'N° Facture de vente / Doc.'],
            'fixedAssets.fields.fiscalGainSpread' => ['Rateizzazione Plusvalenza (Art. 86 TUIR)', 'Capital Gain Tax Spread (Years)', 'Steuerverteilung Gewinn (Jahre)', 'Étalement plus-value (Années)'],
            'fixedAssets.fields.applyToTaxBook' => ['Riduci anche Valore Fiscale (CH)', 'Also Impair Fiscal Book (CH)', 'Auch Steuerbuch abwerten (CH)', 'Déprécier aussi livre fiscal (CH)'],

            'fixedAssets.tabs.parallelSchedule' => ['Confronto Piani Paralleli', 'Parallel Schedule Comparison', 'Paralleler Abschreibungsplan', 'Plans d\'Amortissement Parallèles'],
            'fixedAssets.tabs.extraordinaryEvents' => ['Eventi Straordinari', 'Extraordinary Events', 'Ausserordentliche Ereignisse', 'Événements Extraordinaires'],

            'fixedAssets.labels.civilBook' => ['Piano Civilistico', 'Civil / Statutory Book', 'Handelsrechtliches Buch', 'Livre Statutaire / Civil'],
            'fixedAssets.labels.fiscalBook' => ['Piano Fiscale', 'Fiscal / Tax Book', 'Steuerliches Buch', 'Livre Fiscal'],
            'fixedAssets.labels.temporaryDiff' => ['Differenza Temporanea', 'Temporary Difference', 'Temporäre Differenz', 'Différence Temporaire'],
            'fixedAssets.labels.taxCredit' => ['Credito d\'Imposta 4.0/5.0', '4.0/5.0 Tax Credit', 'Steuergutschrift 4.0/5.0', 'Crédit d\'impôt 4.0/5.0'],
            'fixedAssets.labels.extraDeduction' => ['Maggiorazione Fiscale', 'Extra Fiscal Deduction', 'Steuerliche Zusatzabschreibung', 'Déduction fiscale majorée'],
            'fixedAssets.labels.civilGainLoss' => ['Plus/Minusvalenza Civilistica', 'Civil Gain / Loss', 'Handelsrechtl. Gewinn/Verlust', 'Plus/Moins-value civile'],
            'fixedAssets.labels.fiscalGainLoss' => ['Plus/Minusvalenza Fiscale', 'Fiscal Gain / Loss', 'Steuerlicher Gewinn/Verlust', 'Plus/Moins-value fiscale'],
            'fixedAssets.labels.firstYearHalf' => ['Riduzione 50% 1° Esercizio (D.M. 1988)', '50% First-Year Rule (D.M. 1988)', '50% im 1. Jahr (IT D.M. 1988)', 'Réduction 50% 1re année (D.M. 1988)'],
            'fixedAssets.labels.improvementsSection' => ['Spese Incrementative & Manutenzioni Capitalizzate', 'Capital Improvements & Extraordinary Maintenance', 'Aktivierbare Wertvermehrungen', 'Améliorations capitalisées'],
            'fixedAssets.labels.valuationsSection' => ['Svalutazioni (Impairment) & Rivalutazioni', 'Impairments & Revaluations', 'Wertminderungen & Neubewertungen', 'Dépréciations & Réévaluations'],
            'fixedAssets.labels.disposalsSection' => ['Cessioni & Dismissioni', 'Disposals & Scrappings', 'Veräusserungen & Abgänge', 'Cessions & Mises au rebut'],
            'fixedAssets.labels.categorySubtotals' => ['Totali per Categoria', 'Category Subtotals', 'Zwischensummen nach Kategorie', 'Sous-totaux par Catégorie'],
            'fixedAssets.labels.grandTotals' => ['Totali Generali Libro Cespiti', 'Register Grand Totals', 'Gesamtsummen Anlageverzeichnis', 'Totaux Généraux du Registre'],

            'fixedAssets.messages.improvementRecorded' => ['Spesa incrementativa capitalizzata con successo.', 'Capital improvement recorded and schedule rebased.', 'Werterhöhung erfolgreich aktiviert.', 'Amélioration capitalisée avec succès.'],
            'fixedAssets.messages.valuationRecorded' => ['Valutazione / impairment registrata con successo.', 'Valuation / impairment recorded successfully.', 'Bewertung / Wertminderung erfolgreich erfasst.', 'Évaluation / dépréciation enregistrée avec succès.'],
            'fixedAssets.messages.disposalRecorded' => ['Dismissione / cessione registrata e contabilizzata.', 'Disposal recorded and posted to GL.', 'Abgang erfolgreich erfasst und verbucht.', 'Sortie / cession enregistrée et comptabilisée.'],
            'fixedAssets.messages.bookConfigured' => ['Parametri del libro fiscale aggiornati.', 'Fiscal book parameters updated.', 'Steuerbuch-Parameter aktualisiert.', 'Paramètres du livre fiscal mis à jour.'],
            'fixedAssets.messages.batchExecuted' => ['Ammortamenti paralleli di fine anno eseguiti e contabilizzati.', 'Year-end parallel depreciation batch executed.', 'Jahresabschluss-Abschreibungen ausgeführt.', 'Lot d\'amortissements de fin d\'année exécuté.'],
            'fixedAssets.messages.runPostedToGl' => ['Scritture di ammortamento contabilizzate a giornale.', 'Depreciation run posted to General Ledger.', 'Abschreibungen ins Hauptbuch gebucht.', 'Écritures d\'amortissement comptabilisées.'],
            'fixedAssets.messages.acquiredFromInvoice' => ['Cespite creato automaticamente dalla riga fattura fornitore.', 'Fixed asset created from vendor invoice line.', 'Anlage aus Lieferantenrechnungszeile erstellt.', 'Immobilisation créée depuis la ligne de facture fournisseur.'],

            'eam.registry.title' => ['Gestione Cespiti e Ammortamenti', 'Fixed Assets Registry', 'Anlagebuchhaltung & Anlagenstamm', 'Comptabilité des immobilisations'],
            'eam.registry.subtitle' => ['Ciclo di vita cespiti e piani paralleli Civilistico / Fiscale IT (D.M. 1988) e CH (AFC)', 'Asset lifecycle & parallel CH (AFC/CO) / IT (D.M. 1988) depreciation schedules', 'Anlagenlebenszyklus & parallele Abschreibungspläne CH (ESTV/OR) / IT (DM 1988)', 'Cycle de vie des immobilisations & plans parallèles CH (AFC/CO) / IT (DM 1988)'],
            'eam.registry.acquireFromInvoice' => ['Acquisisci da Fattura Fornitore', 'Acquire from Vendor Invoice', 'Aus Kreditorenrechnung erfassen', 'Acquérir depuis facture fournisseur'],
            'eam.registry.pendingInvoices' => ['Righe Fatture Fornitore da Capitalizzare', 'Pending Vendor Invoice Capital Lines', 'Zu aktivierende Kreditorenpositionen', 'Lignes de factures fournisseurs à immobiliser'],
            'eam.registry.yearEndBatch' => ['Ammortamenti Fine Esercizio', 'Year-End Depreciation Batch', 'Jahresabschluss-Abschreibung', 'Amortissement de fin d’exercice'],
            'eam.registry.officialRegister' => ['Libro Cespiti Ammortizzabili', 'Official Fixed Asset Register', 'Offizielles Anlagenverzeichnis', 'Registre officiel des immobilisations'],
            'eam.field.assetNumber' => ['Matricola / Codice Inventario', 'Asset / Inventory Code', 'Anlagenummer / Inventarcode', 'Numéro d’inventaire / Matricule'],
            'eam.field.purchaseInvoiceNumber' => ['Rif. Fattura d’Acquisto', 'Purchase Invoice Ref.', 'Einkaufsrechnungs-Nr.', 'Réf. facture d’achat'],
            'eam.field.costCenter' => ['Centro di Costo', 'Cost Center', 'Kostenstelle', 'Centre de coûts'],
            'eam.field.location' => ['Ubicazione', 'Location', 'Standort', 'Emplacement'],
            'eam.field.inServiceDate' => ['Data Entrata in Funzione', 'In-Service / Commissioning Date', 'Inbetriebnahmedatum', 'Date de mise en service'],
            'eam.field.fiscalJurisdiction' => ['Giurisdizione Fiscale', 'Tax Jurisdiction', 'Steuerjurisdiktion', 'Juridiction fiscale'],
            'eam.field.fiscalRatePreset' => ['Tabella Coefficienti D.M. 31/12/1988 / AFC', 'AFC / D.M. 1988 Coefficient Table', 'ESTV / DM 1988 Abschreibungssatz', 'Barème fiscal AFC / D.M. 1988'],
            'eam.field.proRataConvention' => ['Convenzione Pro-Rata / Primo Esercizio', 'Pro-Rata / First-Year Convention', 'Pro-Rata-Regel / 1. Jahr', 'Convention prorata / 1re année'],
            'eam.field.immediateExpensing' => ['Deduzione Immediata (CH AFC)', 'Immediate Expensing (CH AFC)', 'Sofortabschreibung (CH ESTV)', 'Amortissement immédiat (CH AFC)'],
            'eam.field.incentiveRegime' => ['Agevolazione Fiscale (4.0 / 5.0 / Super-Iper)', 'Tax Incentive Regime (4.0 / 5.0)', 'Förderregime (IT 4.0 / 5.0)', 'Régime incitatif (IT 4.0 / 5.0)'],
            'eam.field.incentiveRate' => ['Aliquota Maggiorazione / Credito (%)', 'Incentive Rate (%)', 'Fördersatz (%)', 'Taux d’incitation (%)'],
            'eam.status.registered' => ['Registrato', 'Registered', 'Registriert', 'Enregistré'],
            'eam.status.acquired' => ['Acquisito', 'Acquired', 'Erworben', 'Acquis'],
            'eam.status.capitalized' => ['Capitalizzato', 'Capitalized', 'Aktiviert', 'Immobilisé'],
            'eam.status.in_service' => ['In Funzione', 'In Service', 'In Betrieb', 'En service'],
            'eam.status.impaired' => ['Svalutato', 'Impaired', 'Wertgemindert', 'Déprécié'],
            'eam.status.revalued' => ['Rivalutato', 'Revalued', 'Aufgewertet', 'Réévalué'],
            'eam.status.sold' => ['Ceduto', 'Sold', 'Verkauft', 'Cédé'],
            'eam.status.disposed' => ['Dismesso / Rottamato', 'Disposed', 'Ausgeschieden', 'Sorti'],
            'eam.status.retired' => ['Ritirato', 'Retired', 'Stillgelegt', 'Retiré'],
            'eam.book.statutory' => ['Civilistico (Codice Civile / OIC)', 'Civil / Statutory', 'Handelsrechtlich (OR)', 'Comptable statutaire (CO)'],
            'eam.book.tax' => ['Fiscale (D.M. 31/12/1988 / AFC)', 'Fiscal (CH AFC / IT D.M. 1988)', 'Steuerrechtlich (ESTV / DM 1988)', 'Fiscal (AFC / DM 1988)'],
            'eam.book.internal' => ['Gestionale / Analitico', 'Internal / Managerial', 'Betriebswirtschaftlich (Kalk.)', 'Analytique / Interne'],
            'eam.book.configure' => ['Configura Piano di Ammortamento', 'Configure Parallel Book', 'Buch konfigurieren', 'Configurer le livre'],
            'eam.schedule.compareTitle' => ['Confronto Piani di Ammortamento Paralleli', 'Parallel Multi-Book Schedule Comparison', 'Paralleler Abschreibungsplan Handels- vs. Steuerrecht', 'Comparatif des plans parallèles Statutaire vs Fiscal'],
            'eam.schedule.statutoryAmount' => ['Quota Civilistica', 'Civil Depreciation', 'Handelsrechtliche Abschr.', 'Dotation statutaire'],
            'eam.schedule.taxAmount' => ['Quota Fiscale', 'Fiscal Depreciation', 'Steuerliche Abschr.', 'Dotation fiscale'],
            'eam.schedule.tempDiff' => ['Differenza Temporanea (Civil. - Fisc.)', 'Temporary Difference', 'Temporäre Differenz', 'Différence temporaire'],
            'eam.schedule.incentiveCredit' => ['Credito d’Imposta / Extra Deduzione 4.0-5.0', '4.0 / 5.0 Tax Incentive', 'Fördergutschrift 4.0-5.0', 'Crédit / Majoration 4.0-5.0'],
            'eam.events.tabTitle' => ['Eventi Straordinari & Storico', 'Extraordinary Events & History', 'Ausserordentliche Ereignisse & Historie', 'Événements extraordinaires & Historique'],
            'eam.events.improve' => ['Manutenzione Straordinaria / Incremento', 'Capital Improvement', 'Werterhöhende Investition', 'Dépense d’amélioration'],
            'eam.events.impairOrRevalue' => ['Svalutazione / Rivalutazione', 'Impairment / Revaluation', 'Wertberichtigung / Aufwertung', 'Dépréciation / Réévaluation'],
            'eam.events.dispose' => ['Cessione / Dismissione', 'Disposal / Scrapping', 'Verkauf / Verschrottung', 'Cession / Mise au rebut'],
            'eam.events.capitalGain' => ['Plusvalenza', 'Capital Gain', 'Veräusserungsgewinn', 'Plus-value'],
            'eam.events.capitalLoss' => ['Minusvalenza', 'Capital Loss', 'Veräusserungsverlust', 'Moins-value'],
            'eam.events.applyToTaxBook' => ['Applica anche al Registro Fiscale', 'Also apply to Fiscal Book', 'Auch auf Steuerbuch anwenden', 'Appliquer aussi au livre fiscal'],
            'eam.events.previewDisposal' => ['Anteprima Plus/Minusvalenza Civilistica e Fiscale', 'Preview Civil & Fiscal Gain/Loss', 'Vorschau Gewinn/Verlust OR/Steuer', 'Aperçu plus/moins-value comptable et fiscale'],
            'eam.batch.title' => ['Ammortamenti Fine Esercizio e Scritture Co.Ge.', 'Year-End Depreciation & Journal Batch', 'Abschreibungslauf & Verbuchung Hauptbuch', 'Calcul des amortissements & Écritures doubles'],
            'eam.batch.simulate' => ['Simula Ammortamenti', 'Simulate Batch', 'Simulieren (ohne Buchung)', 'Simuler (sans comptabiliser)'],
            'eam.batch.executeAndPost' => ['Esegui e Contabilizza Scritture Fine Esercizio', 'Execute Year-End & Post to GL', 'Berechnen & im Hauptbuch verbuchen', 'Calculer et comptabiliser au grand livre'],
            'eam.batch.postRunToGl' => ['Contabilizza in Partita Doppia', 'Post Run to GL', 'Ins Hauptbuch verbuchen', 'Comptabiliser au grand livre'],
            'eam.register.legalBasis' => ['Registro Cespiti Ammortizzabili ex Art. 16 DPR 600/73 & Art. 958c CO', 'Art. 16 DPR 600/73 (IT) & Art. 958c CO (CH)', 'Art. 958c OR (CH) — Art. 16 DPR 600/73 (IT)', 'Art. 958c CO (CH) — Art. 16 DPR 600/73 (IT)'],
            'eam.register.exportPdf' => ['Stampa / Esporta PDF', 'Export Official PDF', 'Offizielles PDF exportieren', 'Exporter PDF officiel'],
            'eam.register.exportCsv' => ['Esporta CSV', 'Export CSV', 'CSV exportieren', 'Exporter CSV'],
            'eam.register.print' => ['Stampa', 'Print', 'Drucken', 'Imprimer'],
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
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        $out = [];
        foreach (self::definitions() as $key => $row) {
            $out[$key] = $row[$index];
        }

        return $out;
    }
}
