<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the bi-temporal time-travel audit engine (Mod 354, Issue #600): the global time-travel bar with its presets and the amber
 * audit banner, the Merkle integrity pill and the GeBüV / Olico messages, the point-in-time state tables (trial balance, account ledger,
 * aging, inventory), the cut-off diff with the backdated entries and their audit trail, and the what-if valuation replay (WAC, FIFO, LIFO).
 * The codes the API reports are keys too: TIME_TRAVEL.BUCKET.<UPPER>, TIME_TRAVEL.AGGREGATE.<UPPER>, TIME_TRAVEL.METHOD.<UPPER>,
 * TIME_TRAVEL.PARTNER.<UPPER>, TIME_TRAVEL.DIFF.STATUS_<UPPER>. Each entry is [it, en, de, fr]; placeholders are {name}. Legal and technical
 * acronyms (GeBüV, Olico, SHA-256, WAC, FIFO, LIFO, CO, c.c.) stay untranslated.
 */
final class TimeTravelFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::bar(),
            self::presets(),
            self::banner(),
            self::integrity(),
            self::columns(),
            self::codes(),
            self::diff(),
            self::audit(),
            self::whatIf(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function bar(): array
    {
        return [
            'TIME_TRAVEL.BAR.TITLE' => ['Barra Time-Travel', 'Time-travel bar', 'Time-Travel-Leiste', 'Barre Time-Travel'],
            'TIME_TRAVEL.BAR.ACTIVATE' => ['Attiva Time-Travel', 'Enable time travel', 'Time-Travel aktivieren', 'Activer le Time-Travel'],
            'TIME_TRAVEL.BAR.RESET' => ['Torna allo stato attuale', 'Back to live data', 'Zurück zum aktuellen Stand', 'Revenir à l’état actuel'],
            'TIME_TRAVEL.BAR.VALID_AT' => ['Competenza', 'Valid at', 'Stichtag (Gültigkeit)', 'Date de compétence'],
            'TIME_TRAVEL.BAR.VALID_AT_HINT' => ['Data economica di competenza', 'Effective economic date', 'Wirtschaftliches Stichdatum', 'Date économique de compétence'],
            'TIME_TRAVEL.BAR.KNOWN_AT' => ['Conosciuto al', 'Known at', 'Bekannt per', 'Connu au'],
            'TIME_TRAVEL.BAR.KNOWN_AT_HINT' => ['Data e ora di registrazione conosciuta', 'Date and time of recording knowledge', 'Datum und Uhrzeit des bekannten Buchungsstands', 'Date et heure de l’état enregistré connu'],
            'TIME_TRAVEL.BAR.COMPARE_KNOWN_AT' => ['Confronta con conosciuto al', 'Compare with known at', 'Vergleich mit bekannt per', 'Comparer avec connu au'],
            'TIME_TRAVEL.BAR.PRESETS' => ['Preimpostazioni di audit', 'Audit presets', 'Audit-Voreinstellungen', 'Préréglages d’audit'],
            'TIME_TRAVEL.BAR.SHOW_DIFF' => ['Mostra differenze rispetto ad oggi', 'Show differences vs today', 'Unterschiede zu heute anzeigen', 'Afficher les différences par rapport à aujourd’hui'],
            'TIME_TRAVEL.BAR.HIDE_DIFF' => ['Nascondi differenze', 'Hide differences', 'Unterschiede ausblenden', 'Masquer les différences'],
            'TIME_TRAVEL.BAR.WHAT_IF' => ['Simulazione valorizzazione', 'Valuation what-if', 'Bewertungs-Simulation', 'Simulation de valorisation'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function presets(): array
    {
        return [
            'TIME_TRAVEL.PRESET.END_OF_LAST_QUARTER' => ['Fine ultimo trimestre', 'End of last quarter', 'Ende des letzten Quartals', 'Fin du dernier trimestre'],
            'TIME_TRAVEL.PRESET.FISCAL_YEAR_CLOSE' => ['Chiusura esercizio', 'Fiscal year close', 'Geschäftsjahresabschluss', 'Clôture de l’exercice'],
            'TIME_TRAVEL.PRESET.AUDIT_VS_TODAY' => ['Data di revisione vs oggi', 'Audit date vs today', 'Prüfungsstand vs heute', 'Date d’audit vs aujourd’hui'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function banner(): array
    {
        return [
            'TIME_TRAVEL.BANNER.MESSAGE' => [
                '⏱️ Modalità Time-Travel attiva: competenza al {validAt} — stato conosciuto il {knownAt}',
                '⏱️ Time-travel mode active: valid at {validAt} — known as of {knownAt}',
                '⏱️ Time-Travel-Modus aktiv: Stichtag {validAt} — Stand bekannt per {knownAt}',
                '⏱️ Mode Time-Travel actif : compétence au {validAt} — état connu le {knownAt}',
            ],
            'TIME_TRAVEL.BANNER.MESSAGE_DIFF' => [
                '⏱️ Modalità Time-Travel attiva: competenza al {validAt} — stato conosciuto il {knownAt} a confronto con il {comparisonKnownAt}',
                '⏱️ Time-travel mode active: valid at {validAt} — known as of {knownAt} compared with {comparisonKnownAt}',
                '⏱️ Time-Travel-Modus aktiv: Stichtag {validAt} — Stand bekannt per {knownAt} im Vergleich zu {comparisonKnownAt}',
                '⏱️ Mode Time-Travel actif : compétence au {validAt} — état connu le {knownAt} comparé au {comparisonKnownAt}',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function integrity(): array
    {
        return [
            'TIME_TRAVEL.INTEGRITY.VERIFIED' => ['SHA-256 verificato', 'SHA-256 verified', 'SHA-256 verifiziert', 'SHA-256 vérifié'],
            'TIME_TRAVEL.INTEGRITY.BROKEN' => ['Catena alterata', 'Chain tampered', 'Kette manipuliert', 'Chaîne altérée'],
            'TIME_TRAVEL.INTEGRITY.CHECKING' => ['Verifica integrità…', 'Verifying integrity…', 'Integrität wird geprüft…', 'Vérification de l’intégrité…'],
            'TIME_TRAVEL.INTEGRITY.UNAVAILABLE' => ['Integrità non verificabile', 'Integrity unavailable', 'Integrität nicht prüfbar', 'Intégrité non vérifiable'],
            'TIME_TRAVEL.INTEGRITY.COMPLIANCE_OK' => [
                'GeBüV / Olico (art. 957a CO) e art. 2214 c.c.: {events} eventi verificati, nessuna manomissione rilevata nella catena di hash.',
                'GeBüV / Olico (Art. 957a CO) and Art. 2214 c.c.: {events} events verified, no tampering detected in the hash chain.',
                'GeBüV / Olico (Art. 957a OR) und Art. 2214 c.c.: {events} Ereignisse geprüft, keine Manipulation der Hash-Kette festgestellt.',
                'GeBüV / Olico (art. 957a CO) et art. 2214 c.c. : {events} événements vérifiés, aucune manipulation détectée dans la chaîne de hachage.',
            ],
            'TIME_TRAVEL.INTEGRITY.COMPLIANCE_BROKEN' => [
                'Attenzione: la catena di hash è interrotta all’evento n. {sequence} ({event}). I libri non rispettano l’immutabilità richiesta da GeBüV / Olico.',
                'Warning: the hash chain is broken at event no. {sequence} ({event}). The books do not meet the immutability required by GeBüV / Olico.',
                'Achtung: Die Hash-Kette ist bei Ereignis Nr. {sequence} ({event}) unterbrochen. Die Bücher erfüllen die von GeBüV / Olico verlangte Unveränderbarkeit nicht.',
                'Attention : la chaîne de hachage est rompue à l’événement n° {sequence} ({event}). Les livres ne respectent pas l’immuabilité exigée par GeBüV / Olico.',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function columns(): array
    {
        return [
            'TIME_TRAVEL.COL.ACCOUNT' => ['Conto', 'Account', 'Konto', 'Compte'],
            'TIME_TRAVEL.COL.DEBIT' => ['Dare', 'Debit', 'Soll', 'Débit'],
            'TIME_TRAVEL.COL.CREDIT' => ['Avere', 'Credit', 'Haben', 'Crédit'],
            'TIME_TRAVEL.COL.BALANCE' => ['Saldo', 'Balance', 'Saldo', 'Solde'],
            'TIME_TRAVEL.COL.TOTAL' => ['Totale', 'Total', 'Total', 'Total'],
            'TIME_TRAVEL.COL.DATE' => ['Data', 'Date', 'Datum', 'Date'],
            'TIME_TRAVEL.COL.ENTRY' => ['Registrazione', 'Entry', 'Buchung', 'Écriture'],
            'TIME_TRAVEL.COL.DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'TIME_TRAVEL.COL.RUNNING_BALANCE' => ['Saldo progressivo', 'Running balance', 'Laufender Saldo', 'Solde cumulé'],
            'TIME_TRAVEL.COL.DOCUMENT' => ['Documento', 'Document', 'Beleg', 'Document'],
            'TIME_TRAVEL.COL.PARTNER' => ['Controparte', 'Partner', 'Geschäftspartner', 'Partenaire'],
            'TIME_TRAVEL.COL.DUE_DATE' => ['Scadenza', 'Due date', 'Fälligkeit', 'Échéance'],
            'TIME_TRAVEL.COL.AMOUNT' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'TIME_TRAVEL.COL.OPEN_AMOUNT' => ['Residuo aperto', 'Open amount', 'Offener Betrag', 'Montant ouvert'],
            'TIME_TRAVEL.COL.DAYS_OVERDUE' => ['Giorni di ritardo', 'Days overdue', 'Tage überfällig', 'Jours de retard'],
            'TIME_TRAVEL.COL.BUCKET' => ['Fascia', 'Bucket', 'Altersklasse', 'Tranche'],
            'TIME_TRAVEL.COL.ITEM' => ['Articolo', 'Item', 'Artikel', 'Article'],
            'TIME_TRAVEL.COL.WAREHOUSE' => ['Magazzino', 'Warehouse', 'Lager', 'Entrepôt'],
            'TIME_TRAVEL.COL.LOT' => ['Lotto', 'Lot', 'Charge', 'Lot'],
            'TIME_TRAVEL.COL.QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'TIME_TRAVEL.COL.UNIT_COST' => ['Costo unitario', 'Unit cost', 'Stückkosten', 'Coût unitaire'],
            'TIME_TRAVEL.COL.VALUE' => ['Valore', 'Value', 'Wert', 'Valeur'],
            'TIME_TRAVEL.STATE.EVENTS' => ['{count} eventi ricostruiti', '{count} events replayed', '{count} Ereignisse rekonstruiert', '{count} événements reconstruits'],
            'TIME_TRAVEL.STATE.CHECKPOINT_USED' => ['Da checkpoint', 'From checkpoint', 'Aus Checkpoint', 'Depuis un point de contrôle'],
            'TIME_TRAVEL.STATE.FULL_REPLAY' => ['Ricostruzione completa', 'Full replay', 'Vollständige Rekonstruktion', 'Reconstruction complète'],
            'TIME_TRAVEL.STATE.DURATION' => ['{ms} ms', '{ms} ms', '{ms} ms', '{ms} ms'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function codes(): array
    {
        return [
            'TIME_TRAVEL.BUCKET.CURRENT' => ['Non scaduto', 'Not yet due', 'Nicht fällig', 'Non échu'],
            'TIME_TRAVEL.BUCKET.1_30' => ['1–30 giorni', '1–30 days', '1–30 Tage', '1–30 jours'],
            'TIME_TRAVEL.BUCKET.31_60' => ['31–60 giorni', '31–60 days', '31–60 Tage', '31–60 jours'],
            'TIME_TRAVEL.BUCKET.61_90' => ['61–90 giorni', '61–90 days', '61–90 Tage', '61–90 jours'],
            'TIME_TRAVEL.BUCKET.90_PLUS' => ['Oltre 90 giorni', 'Over 90 days', 'Über 90 Tage', 'Plus de 90 jours'],
            'TIME_TRAVEL.PARTNER.CUSTOMER' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'TIME_TRAVEL.PARTNER.SUPPLIER' => ['Fornitore', 'Supplier', 'Lieferant', 'Fournisseur'],
            'TIME_TRAVEL.AGGREGATE.GENERAL_LEDGER' => ['Contabilità generale', 'General ledger', 'Hauptbuch', 'Comptabilité générale'],
            'TIME_TRAVEL.AGGREGATE.OPEN_ITEMS' => ['Partite aperte', 'Open items', 'Offene Posten', 'Postes ouverts'],
            'TIME_TRAVEL.AGGREGATE.INVENTORY_WMS' => ['Magazzino', 'Warehouse inventory', 'Lagerbestand', 'Stock d’entrepôt'],
            'TIME_TRAVEL.AGGREGATE.FIXED_ASSETS' => ['Immobilizzazioni', 'Fixed assets', 'Anlagevermögen', 'Immobilisations'],
            'TIME_TRAVEL.METHOD.WAC' => ['WAC – costo medio ponderato', 'WAC – weighted average cost', 'WAC – gewichteter Durchschnittspreis', 'WAC – coût moyen pondéré'],
            'TIME_TRAVEL.METHOD.FIFO' => ['FIFO – primo entrato, primo uscito', 'FIFO – first in, first out', 'FIFO – first in, first out', 'FIFO – premier entré, premier sorti'],
            'TIME_TRAVEL.METHOD.LIFO' => ['LIFO – ultimo entrato, primo uscito', 'LIFO – last in, first out', 'LIFO – last in, first out', 'LIFO – dernier entré, premier sorti'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function diff(): array
    {
        return [
            'TIME_TRAVEL.DIFF.TITLE' => ['Differenze di cut-off', 'Cut-off differences', 'Cut-off-Differenzen', 'Différences de cut-off'],
            'TIME_TRAVEL.DIFF.COL_LINE' => ['Voce', 'Line', 'Position', 'Ligne'],
            'TIME_TRAVEL.DIFF.COL_BEFORE' => ['Conosciuto al {knownAt}', 'Known at {knownAt}', 'Bekannt per {knownAt}', 'Connu au {knownAt}'],
            'TIME_TRAVEL.DIFF.COL_AFTER' => ['Conosciuto al {knownAt}', 'Known at {knownAt}', 'Bekannt per {knownAt}', 'Connu au {knownAt}'],
            'TIME_TRAVEL.DIFF.COL_DELTA' => ['Delta', 'Delta', 'Delta', 'Delta'],
            'TIME_TRAVEL.DIFF.COL_STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'TIME_TRAVEL.DIFF.COL_EVENT' => ['Evento', 'Event', 'Ereignis', 'Événement'],
            'TIME_TRAVEL.DIFF.COL_SOURCE' => ['Documento origine', 'Source document', 'Quellbeleg', 'Document source'],
            'TIME_TRAVEL.DIFF.COL_ACTIONS' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'TIME_TRAVEL.DIFF.STATUS_UNCHANGED' => ['Invariato', 'Unchanged', 'Unverändert', 'Inchangé'],
            'TIME_TRAVEL.DIFF.STATUS_DELTA' => ['Delta', 'Delta', 'Delta', 'Delta'],
            'TIME_TRAVEL.DIFF.STATUS_BACKDATED' => ['Registrazione retrodatata', 'Backdated entry', 'Rückdatierte Buchung', 'Écriture antidatée'],
            'TIME_TRAVEL.DIFF.BADGE_BACKDATED' => ['Registrazione retrodatata', 'Backdated entry', 'Rückdatierte Buchung', 'Écriture antidatée'],
            'TIME_TRAVEL.DIFF.BACKDATED_TITLE' => ['Registrazioni retrodatate tra i due momenti', 'Backdated entries recorded between the two instants', 'Zwischen den beiden Zeitpunkten rückdatiert erfasste Buchungen', 'Écritures antidatées enregistrées entre les deux instants'],
            'TIME_TRAVEL.DIFF.NO_DIFFERENCES' => ['Nessuna differenza tra i due momenti.', 'No differences between the two instants.', 'Keine Unterschiede zwischen den beiden Zeitpunkten.', 'Aucune différence entre les deux instants.'],
            'TIME_TRAVEL.DIFF.NO_BACKDATED' => ['Nessuna registrazione retrodatata.', 'No backdated entries.', 'Keine rückdatierten Buchungen.', 'Aucune écriture antidatée.'],
            'TIME_TRAVEL.DIFF.OPEN_TRAIL' => ['Traccia di audit', 'Audit trail', 'Prüfpfad', 'Piste d’audit'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function audit(): array
    {
        return [
            'TIME_TRAVEL.AUDIT.TITLE' => ['Traccia di audit dell’evento', 'Event audit trail', 'Prüfpfad des Ereignisses', 'Piste d’audit de l’événement'],
            'TIME_TRAVEL.AUDIT.EVENT_TYPE' => ['Tipo di evento', 'Event type', 'Ereignistyp', 'Type d’événement'],
            'TIME_TRAVEL.AUDIT.AGGREGATE' => ['Aggregato', 'Aggregate', 'Aggregat', 'Agrégat'],
            'TIME_TRAVEL.AUDIT.VALID_AT' => ['Competenza', 'Valid at', 'Stichtag (Gültigkeit)', 'Date de compétence'],
            'TIME_TRAVEL.AUDIT.RECORDED_AT' => ['Registrato il', 'Recorded at', 'Erfasst am', 'Enregistré le'],
            'TIME_TRAVEL.AUDIT.ACTOR' => ['Utente', 'User', 'Benutzer', 'Utilisateur'],
            'TIME_TRAVEL.AUDIT.SOURCE' => ['Documento origine', 'Source document', 'Quellbeleg', 'Document source'],
            'TIME_TRAVEL.AUDIT.HASH' => ['Hash di catena SHA-256', 'SHA-256 chain hash', 'SHA-256-Kettenhash', 'Hash de chaîne SHA-256'],
            'TIME_TRAVEL.AUDIT.PAYLOAD' => ['Contenuto dell’evento', 'Event payload', 'Ereignisinhalt', 'Contenu de l’événement'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function whatIf(): array
    {
        return [
            'TIME_TRAVEL.WHATIF.TITLE' => ['Simulazione di valorizzazione del magazzino', 'Inventory valuation what-if', 'Simulation der Lagerbewertung', 'Simulation de valorisation du stock'],
            'TIME_TRAVEL.WHATIF.SANDBOX_NOTE' => [
                'La simulazione rigioca i movimenti di magazzino in un ambiente isolato: la contabilità e le giacenze ufficiali non vengono modificate.',
                'The simulation replays the stock movements in an isolated sandbox: the official ledger and stock are never changed.',
                'Die Simulation spielt die Lagerbewegungen in einer isolierten Umgebung ab: Buchhaltung und offizielle Bestände bleiben unverändert.',
                'La simulation rejoue les mouvements de stock dans un environnement isolé : la comptabilité et les stocks officiels ne sont pas modifiés.',
            ],
            'TIME_TRAVEL.WHATIF.METHOD' => ['Metodo di valorizzazione', 'Valuation method', 'Bewertungsmethode', 'Méthode de valorisation'],
            'TIME_TRAVEL.WHATIF.NAME' => ['Nome della simulazione', 'Simulation name', 'Name der Simulation', 'Nom de la simulation'],
            'TIME_TRAVEL.WHATIF.RUN' => ['Esegui simulazione', 'Run simulation', 'Simulation starten', 'Lancer la simulation'],
            'TIME_TRAVEL.WHATIF.OFFICIAL_VALUE' => ['Valorizzazione ufficiale', 'Official valuation', 'Offizielle Bewertung', 'Valorisation officielle'],
            'TIME_TRAVEL.WHATIF.SIMULATED_VALUE' => ['Valorizzazione simulata', 'Simulated valuation', 'Simulierte Bewertung', 'Valorisation simulée'],
            'TIME_TRAVEL.WHATIF.VARIANCE' => ['Scostamento', 'Variance', 'Abweichung', 'Écart'],
            'TIME_TRAVEL.WHATIF.VARIANCE_PCT' => ['Scostamento %', 'Variance %', 'Abweichung %', 'Écart %'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'TIME_TRAVEL.ERROR.GENERIC' => ['Impossibile ricostruire lo stato richiesto. Riprovare tra poco.', 'The requested state could not be reconstructed. Try again shortly.', 'Der gewünschte Stand konnte nicht rekonstruiert werden. Bitte gleich erneut versuchen.', 'L’état demandé n’a pas pu être reconstruit. Réessayez dans un instant.'],
            'TIME_TRAVEL.ERROR.FORBIDDEN' => ['Non hai i permessi per consultare il Time-Travel contabile.', 'You are not allowed to use accounting time travel.', 'Sie sind nicht berechtigt, das Buchhaltungs-Time-Travel zu nutzen.', 'Vous n’avez pas le droit d’utiliser le Time-Travel comptable.'],
            'TIME_TRAVEL.ERROR.INVALID' => ['I parametri indicati non sono validi: controllare le date e i filtri.', 'The parameters are not valid: check the dates and the filters.', 'Die Parameter sind ungültig: Bitte Daten und Filter prüfen.', 'Les paramètres ne sont pas valides : vérifiez les dates et les filtres.'],
            'TIME_TRAVEL.ERROR.RANGE' => ['Il momento di confronto non può precedere quello di partenza.', 'The comparison instant cannot precede the starting instant.', 'Der Vergleichszeitpunkt darf nicht vor dem Ausgangszeitpunkt liegen.', 'L’instant de comparaison ne peut pas précéder l’instant de départ.'],
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

        $resolved = [];

        foreach (self::definitions() as $key => $tuple) {
            $resolved[$key] = $tuple[$index];
        }

        return $resolved;
    }
}
