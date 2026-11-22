<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the General Ledger prima nota workbench, journal inspector, year-end closing wizard and the
 * trial balance / mastrino views (Mod 012). Each entry is [it, en, de, fr].
 */
final class GeneralLedgerPrimaNotaFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'GL.NAV_PRIMA_NOTA' => ['Prima nota', 'Prima nota', 'Primanota', 'Saisie des écritures'],
            'GL.NAV_YEAR_END_CLOSING' => ['Chiusura d\'esercizio', 'Year-end closing', 'Jahresabschluss', 'Clôture annuelle'],
            'GL.PN_TITLE' => ['Prima nota - inserimento rapido', 'Prima nota - fast entry', 'Primanota - Schnellerfassung', 'Saisie rapide des écritures'],
            'GL.PN_POSTING_DATE' => ['Data di registrazione', 'Posting date', 'Buchungsdatum', 'Date de comptabilisation'],
            'GL.PN_DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Libellé'],
            'GL.PN_BOOK' => ['Libro', 'Ledger book', 'Buch', 'Livre'],
            'GL.PN_BOOK_01' => ['01 Civilistico', '01 Statutory', '01 Handelsrechtlich', '01 Statutaire'],
            'GL.PN_BOOK_02' => ['02 Fiscale', '02 Tax', '02 Steuerlich', '02 Fiscal'],
            'GL.PN_BOOK_03' => ['03 IFRS', '03 IFRS', '03 IFRS', '03 IFRS'],
            'GL.PN_ALL_BOOKS' => ['Tutti i libri', 'All books', 'Alle Bücher', 'Tous les livres'],
            'GL.PN_PROPAGATE' => ['Registra anche in', 'Also post to', 'Zusätzlich buchen in', 'Comptabiliser aussi dans'],
            'GL.PN_TEMPLATE' => ['Causale contabile', 'Accounting template', 'Buchungsvorlage', 'Modèle d\'écriture'],
            'GL.PN_TEMPLATE_PLACEHOLDER' => ['Scegli una causale (Alt+T)', 'Choose a template (Alt+T)', 'Vorlage wählen (Alt+T)', 'Choisir un modèle (Alt+T)'],
            'GL.PN_TEMPLATE_AMOUNT' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'GL.PN_TEMPLATE_RATE' => ['Aliquota %', 'Tax rate %', 'Steuersatz %', 'Taux de taxe %'],
            'GL.PN_TEMPLATE_APPLY' => ['Compila le righe', 'Fill the lines', 'Zeilen ausfüllen', 'Remplir les lignes'],
            'GL.PN_LINES' => ['Righe della registrazione', 'Journal lines', 'Buchungszeilen', 'Lignes d\'écriture'],
            'GL.PN_DEBIT' => ['Dare', 'Dare', 'Soll', 'Débit'],
            'GL.PN_CREDIT' => ['Avere', 'Avere', 'Haben', 'Crédit'],
            'GL.PN_REMOVE_LINE' => ['Rimuovi riga', 'Remove line', 'Zeile entfernen', 'Supprimer la ligne'],
            'GL.PN_DEBIT_TOTAL' => ['Totale Dare', 'Total Dare', 'Summe Soll', 'Total débit'],
            'GL.PN_CREDIT_TOTAL' => ['Totale Avere', 'Total Avere', 'Summe Haben', 'Total crédit'],
            'GL.PN_DIFFERENCE' => ['Differenza', 'Difference', 'Differenz', 'Écart'],
            'GL.PN_BALANCED' => ['Quadrata', 'Balanced', 'Ausgeglichen', 'Équilibrée'],
            'GL.PN_NOT_BALANCED' => ['Non quadrata', 'Not balanced', 'Nicht ausgeglichen', 'Non équilibrée'],
            'GL.PN_ISSUES' => ['Cosa blocca la registrazione', 'What blocks posting', 'Was die Buchung blockiert', 'Ce qui bloque la comptabilisation'],
            'GL.PN_ISSUE_DESCRIPTION_REQUIRED' => ['Inserisci una descrizione.', 'Enter a description.', 'Beschreibung eingeben.', 'Saisissez un libellé.'],
            'GL.PN_ISSUE_MIN_LINES' => ['Servono almeno due righe.', 'At least two lines are required.', 'Mindestens zwei Zeilen erforderlich.', 'Au moins deux lignes sont requises.'],
            'GL.PN_ISSUE_ACCOUNT_REQUIRED' => ['Ogni riga richiede un conto dall\'elenco.', 'Every line needs an account from the list.', 'Jede Zeile braucht ein Konto aus der Liste.', 'Chaque ligne requiert un compte de la liste.'],
            'GL.PN_ISSUE_AMOUNT_REQUIRED' => ['Ogni riga richiede un importo.', 'Every line needs an amount.', 'Jede Zeile braucht einen Betrag.', 'Chaque ligne requiert un montant.'],
            'GL.PN_ISSUE_BOTH_SIDES' => ['Una riga non può avere sia Dare sia Avere.', 'A line cannot have both Dare and Avere.', 'Eine Zeile kann nicht Soll und Haben haben.', 'Une ligne ne peut avoir à la fois débit et crédit.'],
            'GL.PN_ISSUE_UNBALANCED' => ['Dare e Avere devono coincidere.', 'Dare and Avere must be equal.', 'Soll und Haben müssen gleich sein.', 'Débit et crédit doivent être égaux.'],
            'GL.PN_ISSUE_NOTHING_TO_POST' => ['Inserisci almeno un importo.', 'Enter at least one amount.', 'Mindestens einen Betrag eingeben.', 'Saisissez au moins un montant.'],
            'GL.PN_ADD_LINE' => ['Aggiungi riga', 'Add line', 'Zeile hinzufügen', 'Ajouter une ligne'],
            'GL.PN_BALANCE' => ['Quadra', 'Balance', 'Ausgleichen', 'Équilibrer'],
            'GL.PN_SAVE_DRAFT' => ['Salva bozza', 'Save draft', 'Entwurf speichern', 'Enregistrer le brouillon'],
            'GL.PN_POST' => ['Registra', 'Post', 'Buchen', 'Comptabiliser'],
            'GL.PN_OPEN_JOURNALS' => ['Apri il giornale', 'Open the journal list', 'Journalliste öffnen', 'Ouvrir la liste des écritures'],
            'GL.PN_SHORTCUTS' => ['Scorciatoie: Alt+N aggiungi riga, Alt+B quadra, Alt+L libro successivo, Alt+T causale, Ctrl+S salva bozza, Ctrl+Invio registra.', 'Shortcuts: Alt+N add line, Alt+B balance, Alt+L next book, Alt+T template, Ctrl+S save draft, Ctrl+Enter post.', 'Tastenkürzel: Alt+N Zeile, Alt+B ausgleichen, Alt+L nächstes Buch, Alt+T Vorlage, Strg+S Entwurf, Strg+Enter buchen.', 'Raccourcis : Alt+N ajouter une ligne, Alt+B équilibrer, Alt+L livre suivant, Alt+T modèle, Ctrl+S brouillon, Ctrl+Entrée comptabiliser.'],
            'GL.PN_POSTED' => ['Registrazione effettuata', 'Journal posted', 'Buchung durchgeführt', 'Écriture comptabilisée'],
            'GL.PN_DRAFT_SAVED' => ['Bozza salvata', 'Draft saved', 'Entwurf gespeichert', 'Brouillon enregistré'],
            'GL.PN_SEARCH_JOURNALS' => ['Cerca numero o descrizione', 'Search entry or description', 'Nummer oder Beschreibung suchen', 'Rechercher numéro ou libellé'],
            'GL.ACTION_INSPECT' => ['Ispeziona', 'Inspect', 'Prüfen', 'Inspecter'],
            'GL.INSPECTOR_TITLE' => ['Registrazione contabile', 'Journal entry', 'Buchungssatz', 'Écriture comptable'],
            'GL.JOURNAL_STORNO_REASON' => ['Storno dal giornale', 'Storno from the journal list', 'Storno aus der Journalliste', 'Extourne depuis la liste des écritures'],
            'GL.DIVISION' => ['Divisione', 'Division', 'Sparte', 'Division'],
            'GL.TB_COLUMNS' => ['Formato', 'Layout', 'Darstellung', 'Présentation'],
            'GL.TB_COLUMNS_2' => ['2 colonne (saldi)', '2 columns (balances)', '2 Spalten (Salden)', '2 colonnes (soldes)'],
            'GL.TB_COLUMNS_4' => ['4 colonne (movimenti)', '4 columns (movements)', '4 Spalten (Bewegungen)', '4 colonnes (mouvements)'],
            'GL.TB_DEBIT_BALANCE' => ['Saldo dare', 'Debit balance', 'Sollsaldo', 'Solde débiteur'],
            'GL.TB_CREDIT_BALANCE' => ['Saldo avere', 'Credit balance', 'Habensaldo', 'Solde créditeur'],
            'GL.COL_ACCOUNT_CODE' => ['Codice conto', 'Account code', 'Kontonummer', 'Code compte'],
            'GL.COL_ACCOUNT_NAME' => ['Descrizione conto', 'Account name', 'Kontobezeichnung', 'Libellé du compte'],
            'GL.EXPORT_CSV' => ['Esporta CSV', 'Export CSV', 'CSV exportieren', 'Exporter en CSV'],
            'GL.EXPORT_JSON' => ['Esporta JSON', 'Export JSON', 'JSON exportieren', 'Exporter en JSON'],
            'GL.PRINT' => ['Stampa', 'Print', 'Drucken', 'Imprimer'],
            'GL.DRILL_DOWN' => ['Apri il mastrino', 'Open the account ledger', 'Kontoblatt öffnen', 'Ouvrir le grand livre du compte'],
            'GL.YEC_TITLE' => ['Chiusura d\'esercizio', 'Year-end closing', 'Jahresabschluss', 'Clôture annuelle'],
            'GL.YEC_FISCAL_YEAR' => ['Esercizio', 'Fiscal year', 'Geschäftsjahr', 'Exercice'],
            'GL.YEC_SELECT_YEAR' => ['Seleziona l\'esercizio', 'Select the fiscal year', 'Geschäftsjahr wählen', 'Sélectionnez l\'exercice'],
            'GL.YEC_COMPLETE' => ['Chiusura completata', 'Closing complete', 'Abschluss abgeschlossen', 'Clôture terminée'],
            'GL.YEC_STATUS_COMPLETED' => ['Completata', 'Completed', 'Abgeschlossen', 'Terminée'],
            'GL.YEC_STATUS_READY' => ['Pronta', 'Ready', 'Bereit', 'Prête'],
            'GL.YEC_STATUS_BLOCKED' => ['Bloccata da una fase precedente', 'Blocked by an earlier stage', 'Durch eine frühere Stufe blockiert', 'Bloquée par une étape précédente'],
            'GL.YEC_STAGE_PERIOD_LOCK' => ['Blocco dei periodi', 'Lock the periods', 'Perioden sperren', 'Verrouillage des périodes'],
            'GL.YEC_STAGE_NET_RESULT' => ['Determinazione del risultato', 'Net result', 'Jahresergebnis', 'Résultat net'],
            'GL.YEC_STAGE_CLOSING' => ['Scritture di chiusura', 'Closing entries', 'Abschlussbuchungen', 'Écritures de clôture'],
            'GL.YEC_STAGE_OPENING' => ['Scritture di apertura', 'Opening entries', 'Eröffnungsbuchungen', 'Écritures d\'ouverture'],
            'GL.YEC_STAGE_DESC_PERIOD_LOCK' => ['I periodi dell\'esercizio non accettano più registrazioni.', 'No more postings are accepted in the periods of the year.', 'In den Perioden des Jahres werden keine Buchungen mehr angenommen.', 'Les périodes de l\'exercice n\'acceptent plus d\'écritures.'],
            'GL.YEC_STAGE_DESC_NET_RESULT' => ['Calcola l\'utile o la perdita dell\'esercizio dai conti economici.', 'Computes the profit or loss of the year from the revenue and expense accounts.', 'Ermittelt den Gewinn oder Verlust des Jahres aus den Erfolgskonten.', 'Calcule le bénéfice ou la perte de l\'exercice à partir des comptes de résultat.'],
            'GL.YEC_STAGE_DESC_CLOSING' => ['Registra la scrittura che azzera i conti economici e porta il risultato a patrimonio netto.', 'Posts the closing entry that zeroes the income statement accounts and moves the result to equity.', 'Bucht den Abschluss, der die Erfolgskonten ausgleicht und das Ergebnis ins Eigenkapital überträgt.', 'Comptabilise l\'écriture qui solde les comptes de résultat et reporte le résultat en capitaux propres.'],
            'GL.YEC_STAGE_DESC_OPENING' => ['Registra i saldi di apertura dei conti patrimoniali nell\'esercizio successivo.', 'Posts the opening balances of the balance sheet accounts in the next year.', 'Bucht die Eröffnungssalden der Bilanzkonten im Folgejahr.', 'Comptabilise les soldes d\'ouverture des comptes de bilan de l\'exercice suivant.'],
            'GL.YEC_EXECUTED_AT' => ['Eseguita il', 'Executed at', 'Ausgeführt am', 'Exécutée le'],
            'GL.YEC_OPEN_JOURNAL' => ['Apri la registrazione', 'Open the journal entry', 'Buchungssatz öffnen', 'Ouvrir l\'écriture'],
            'GL.YEC_RUN_STAGE' => ['Esegui questa fase', 'Run this stage', 'Diese Stufe ausführen', 'Exécuter cette étape'],
            'GL.YEC_CONFIRM_TITLE' => ['Eseguire la fase di chiusura?', 'Run the closing stage?', 'Abschlussstufe ausführen?', 'Exécuter l\'étape de clôture ?'],
            'GL.YEC_CONFIRM_TEXT' => ['La fase registra scritture e non può essere annullata. Vale solo per l\'esercizio e il libro selezionati.', 'The stage posts entries and cannot be undone. It applies to the selected fiscal year and ledger book only.', 'Die Stufe bucht Buchungssätze und kann nicht rückgängig gemacht werden. Sie gilt nur für das gewählte Geschäftsjahr und Buch.', 'L\'étape comptabilise des écritures et ne peut pas être annulée. Elle ne concerne que l\'exercice et le livre sélectionnés.'],
            'GL.YEC_STAGE_DONE' => ['Fase di chiusura completata', 'Closing stage completed', 'Abschlussstufe abgeschlossen', 'Étape de clôture terminée'],
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
