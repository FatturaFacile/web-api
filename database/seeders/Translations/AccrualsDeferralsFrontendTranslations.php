<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings for Mod 022 Multi-Period Accruals & Deferrals (Ratei e Risconti / Transitorische Konten):
 * competence date pickers, live calculation previews, year-end closing & opening reversal automation,
 * and reconciliation worksheet export. Each entry is [it, en, de, fr].
 */
final class AccrualsDeferralsFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            // Transitory posting roles in GL settings
            'GL.ROLE_PREPAID_EXPENSE' => ['Risconti attivi', 'Prepaid expenses (active deferrals)', 'Aktive Rechnungsabgrenzung (Transitorische Aktiven)', 'Charges constatées d\'avance'],
            'GL.ROLE_DEFERRED_REVENUE' => ['Risconti passivi', 'Deferred revenue (passive deferrals)', 'Passive Rechnungsabgrenzung (Transitorische Passiven)', 'Produits constatés d\'avance'],
            'GL.ROLE_ACCRUED_REVENUE' => ['Ratei attivi', 'Accrued revenue (active accruals)', 'Aktive Abgrenzungsposten (Forderungen)', 'Produits à recevoir'],
            'GL.ROLE_ACCRUED_EXPENSE' => ['Ratei passivi', 'Accrued expenses (passive accruals)', 'Passive Abgrenzungsposten (Verbindlichkeiten)', 'Charges à payer'],

            // Competence period picker & live preview (shared across invoices, bills, prima nota)
            'GL.COMPETENCE_PERIOD' => ['Periodo di competenza economica', 'Economic competence period', 'Abgrenzungszeitraum', 'Période de compétence économique'],
            'GL.COMPETENCE_START' => ['Data inizio competenza', 'Competence start date', 'Beginn Abgrenzung', 'Date de début de compétence'],
            'GL.COMPETENCE_END' => ['Data fine competenza', 'Competence end date', 'Ende Abgrenzung', 'Date de fin de compétence'],
            'GL.COMPETENCE_PRESET_YEAR' => ['Anno solare', 'Full year', 'Kalenderjahr', 'Année civile'],
            'GL.COMPETENCE_PRESET_12M' => ['12 mesi', '12 months', '12 Monate', '12 mois'],
            'GL.COMPETENCE_PAIR_REQUIRED' => ['Indica sia la data di inizio sia la data di fine competenza.', 'Provide both competence start and end dates.', 'Start- und Enddatum der Abgrenzung gemeinsam angeben.', 'Veuillez saisir les dates de début et de fin de compétence.'],
            'GL.COMPETENCE_END_BEFORE_START' => ['La fine competenza non può precedere l\'inizio.', 'Competence end date cannot precede start date.', 'Das Enddatum darf nicht vor dem Startdatum liegen.', 'La date de fin ne peut pas précéder la date de début.'],
            'GL.COMPETENCE_PREVIEW_DAYS' => ['Giorni nell\'esercizio / totali', 'Period / total days', 'Tage im Jahr / gesamt', 'Jours de l\'exercice / total'],
            'GL.COMPETENCE_RECOGNIZED' => ['Quota di competenza esercizio', 'Current-year recognized', 'Anteil laufendes Jahr', 'Quote-part de l\'exercice'],
            'GL.COMPETENCE_CARRYOVER' => ['Quota transitoria (a nuovo)', 'Carryover / accrual share', 'Transitorischer Anteil', 'Quote transitoire (report)'],
            'GL.COMPETENCE_IN_PERIOD' => ['Interamente nell\'esercizio', 'Fully within fiscal year', 'Vollständig im Geschäftsjahr', 'Intégralement dans l\'exercice'],

            // Accruals & Deferrals Workbench
            'GL.ACCRUALS_TITLE' => ['Ratei, Risconti e Conti Transitori (Mod 022)', 'Accruals, Deferrals & Transitory Accounts (Mod 022)', 'Transitorische Konten / Rechnungsabgrenzung (Mod 022)', 'Régularisations et Comptes de Régularisation (Mod 022)'],
            'GL.ACCRUALS_SUBTITLE' => ['Riconoscimento economico multi-periodo (CO 958b / Art. 2423-bis c.c. / OIC 18), chiusure al 31/12 e storni automatici al 01/01.', 'Multi-period economic matching (CO 958b / OIC 18), 31/12 closing adjustments and 01/01 automatic reversals.', 'Periodengerechte Abgrenzung (OR 958b / OIC 18), Abschlussbuchungen per 31.12. und Rückbuchungen per 01.01.', 'Rattachement économique multi-périodes (CO 958b / OIC 18), clôtures au 31/12 et extournes au 01/01.'],
            'GL.ACCRUALS_FISCAL_YEAR' => ['Esercizio fiscale', 'Fiscal year', 'Geschäftsjahr', 'Exercice fiscal'],
            'GL.ACCRUALS_FILTER_STATUS' => ['Stato scrittura', 'Posting status', 'Buchungsstatus', 'Statut d\'écriture'],
            'GL.ACCRUALS_FILTER_SOURCE' => ['Origine', 'Source', 'Quelle', 'Origine'],
            'GL.ACCRUALS_FILTER_ACCOUNT' => ['Conto', 'Account', 'Konto', 'Compte'],
            'GL.ACCRUALS_SEARCH_PLACEHOLDER' => ['Cerca descrizione, documento o conto...', 'Search description, document or account...', 'Beschreibung, Beleg oder Konto suchen...', 'Rechercher libellé, document ou compte...'],
            'GL.ACCRUALS_ALL_SOURCES' => ['Tutte le origini', 'All sources', 'Alle Quellen', 'Toutes les origines'],
            'GL.ACCRUALS_ALL_STATUSES' => ['Tutti gli stati', 'All statuses', 'Alle Status', 'Tous les statuts'],

            // Sources
            'GL.ACCRUAL_SRC_MANUAL' => ['Inserimento manuale', 'Manual accrual', 'Manuelle Abgrenzung', 'Régularisation manuelle'],
            'GL.ACCRUAL_SRC_SALES_INVOICE_LINE' => ['Fattura di vendita', 'Sales invoice line', 'Ausgangsrechnung', 'Facture de vente'],
            'GL.ACCRUAL_SRC_PASSIVE_INVOICE_LINE' => ['Fattura fornitore', 'Supplier bill line', 'Eingangsrechnung', 'Facture fournisseur'],
            'GL.ACCRUAL_SRC_JOURNAL_LINE' => ['Prima nota', 'Journal line', 'Primanota-Zeile', 'Ligne d\'écriture'],

            // Statuses
            'GL.ACCRUAL_STATUS_CALCULATED' => ['Da contabilizzare (31/12)', 'Pending closing (31/12)', 'Offen (Abschluss 31.12.)', 'À comptabiliser (31/12)'],
            'GL.ACCRUAL_STATUS_CLOSING_POSTED' => ['Assestato al 31/12', 'Closing posted (31/12)', 'Abgegrenzt per 31.12.', 'Clôturé au 31/12'],
            'GL.ACCRUAL_STATUS_REVERSAL_POSTED' => ['Stornato al 01/01', 'Reversed (01/01)', 'Zurückgebucht per 01.01.', 'Extourné au 01/01'],

            // KPIs
            'GL.ACCRUAL_KPI_PREPAID_EXPENSE' => ['Risconti attivi', 'Prepaid expenses', 'Transitorische Aktiven', 'Charges constatées d\'avance'],
            'GL.ACCRUAL_KPI_DEFERRED_REVENUE' => ['Risconti passivi', 'Deferred revenue', 'Transitorische Passiven', 'Produits constatés d\'avance'],
            'GL.ACCRUAL_KPI_ACCRUED_REVENUE' => ['Ratei attivi', 'Accrued revenue', 'Aktive Abgrenzung (Ratei)', 'Produits à recevoir'],
            'GL.ACCRUAL_KPI_ACCRUED_EXPENSE' => ['Ratei passivi', 'Accrued expenses', 'Passive Abgrenzung (Ratei)', 'Charges à payer'],
            'GL.ACCRUAL_KPI_PENDING_CLOSING' => ['Chiusure 31/12 pendenti', 'Pending 31/12 closings', 'Offene Buchungen 31.12.', 'Clôtures 31/12 en attente'],
            'GL.ACCRUAL_KPI_REVERSALS' => ['Storni 01/01 (eseguiti / pendenti)', '01/01 reversals (done / pending)', 'Rückbuchungen 01.01. (erledigt / offen)', 'Extournes 01/01 (faites / en attente)'],

            // Actions & Table columns
            'GL.ACCRUALS_GENERATE_CLOSING' => ['Genera scritture 31/12', 'Generate 31/12 closing entries', 'Abschlussbuchungen 31.12. erstellen', 'Générer écritures 31/12'],
            'GL.ACCRUALS_GENERATE_CLOSING_HINT' => ['Contabilizza con 1 clic le scritture di assestamento alla data di chiusura per le righe selezionate o tutte le pendenti.', 'Post balanced year-end adjustment entries on the cut-off date for selected or all pending items.', 'Erstellt mit 1 Klick die Abgrenzungsbuchungen zum Stichtag für ausgewählte oder alle offenen Positionen.', 'Comptabilise en 1 clic les écritures de régularisation à la date de clôture pour les lignes sélectionnées ou en attente.'],
            'GL.ACCRUALS_ALSO_REVERSAL' => ['Genera subito anche gli storni al 01/01', 'Also generate 01/01 opening reversals', 'Rückbuchungen per 01.01. direkt mit erstellen', 'Générer aussi les extournes au 01/01'],
            'GL.ACCRUALS_GENERATE_REVERSALS' => ['Esegui storni 01/01', 'Post 01/01 reversals', 'Rückbuchungen 01.01. buchen', 'Passer les extournes 01/01'],
            'GL.ACCRUALS_EXPORT_RECONCILIATION' => ['Esporta riconciliazione revisori', 'Export auditor reconciliation', 'Abstimmungsblatt exportieren', 'Exporter rapprochement réviseurs'],
            'GL.ACCRUALS_EXPORT_RECONCILIATION_XLSX' => ['Esporta riconciliazione (Excel)', 'Export reconciliation (Excel)', 'Abstimmungsblatt als Excel exportieren', 'Exporter le rapprochement (Excel)'],
            'GL.ACCRUALS_COL_SOURCE' => ['Origine / Documento', 'Source / Document', 'Quelle / Beleg', 'Origine / Document'],
            'GL.ACCRUALS_COL_ACCOUNTS' => ['Conto economico / Patrimoniale', 'Income / Balance account', 'Erfolgs- / Bilanzkonto', 'Compte de résultat / Bilan'],
            'GL.ACCRUALS_COL_RECOGNIZED' => ['Competenza anno', 'Recognized (FY)', 'Anteil Geschäftsjahr', 'Quote exercice'],
            'GL.ACCRUALS_COL_TRANSITORY' => ['Quota transitoria', 'Transitory balance', 'Transitorischer Saldo', 'Solde transitoire'],
            'GL.ACCRUALS_COL_CLOSING_ENTRY' => ['Scrittura 31/12', '31/12 Closing entry', 'Buchung 31.12.', 'Écriture 31/12'],
            'GL.ACCRUALS_COL_REVERSAL_ENTRY' => ['Storno 01/01', '01/01 Reversal entry', 'Storno 01.01.', 'Extourne 01/01'],
            'GL.ACCRUALS_CLOSING_SUCCESS' => ['Scritture di chiusura 31/12 elaborate: :posted registrate, :skipped saltate, :failed errori.', '31/12 closing entries processed: :posted posted, :skipped skipped, :failed failed.', 'Abschlussbuchungen 31.12. verarbeitet: :posted gebucht, :skipped übersprungen, :failed Fehler.', 'Écritures de clôture 31/12 traitées : :posted comptabilisées, :skipped ignorées, :failed erreurs.'],
            'GL.ACCRUALS_REVERSAL_SUCCESS' => ['Storni 01/01 elaborati: :reversed registrati, :skipped saltati, :failed errori.', '01/01 reversals processed: :reversed posted, :skipped skipped, :failed failed.', 'Rückbuchungen 01.01. verarbeitet: :reversed gebucht, :skipped übersprungen, :failed Fehler.', 'Extournes 01/01 traitées : :reversed comptabilisées, :skipped ignorées, :failed erreurs.'],
            'GL.ACCRUALS_NO_PENDING' => ['Nessuna riga idonea da elaborare.', 'No eligible rows to process.', 'Keine geeigneten Positionen vorhanden.', 'Aucune ligne éligible à traiter.'],
            'GL.ACCRUALS_PREVIEW_TITLE' => ['Anteprima calcolo competenza', 'Competence calculation preview', 'Vorschau Abgrenzungsberechnung', 'Aperçu du calcul de compétence'],

            // Supplier bill line competence action
            'PURCHASES.COMPETENCE_ACTION' => ['Competenza', 'Competence', 'Abgrenzung', 'Compétence'],
            'PURCHASES.COMPETENCE_MODAL_TITLE' => ['Periodo di competenza riga fattura fornitore', 'Supplier bill line competence period', 'Abgrenzungszeitraum Eingangsrechnungszeile', 'Période de compétence ligne fournisseur'],
            'PURCHASES.COMPETENCE_SAVED' => ['Date di competenza aggiornate', 'Competence dates updated', 'Abgrenzungsdaten aktualisiert', 'Dates de compétence mises à jour'],
            'RES.A.set_line_competence' => ['Imposta competenza', 'Set competence dates', 'Abgrenzung festlegen', 'Définir compétence'],
            'RES.F.competence_start_date' => ['Inizio competenza', 'Competence start', 'Beginn Abgrenzung', 'Début compétence'],
            'RES.F.competence_end_date' => ['Fine competenza', 'Competence end', 'Ende Abgrenzung', 'Fin compétence'],
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
