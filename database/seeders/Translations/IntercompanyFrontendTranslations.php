<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the intercompany and consolidation module (Mod 021): group structure and ownership, mirror postings,
 * netting, the reciprocal reconciliation cockpit, the guided consolidation run, the consolidated statements in FER 30,
 * IFRS 10 and OIC 17, and the consolidated package. Also the labels of every enum code and the texts of the error codes the
 * API returns (`intercompany.error.<code>`), so the client shows no text of its own.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (FER, IFRS, OIC, NCI, SDI, IVA) stay untranslated. Placeholders are `{name}`.
 */
final class IntercompanyFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            ...self::navigation(),
            ...self::common(),
            ...self::structure(),
            ...self::transactions(),
            ...self::netting(),
            ...self::reconciliation(),
            ...self::run(),
            ...self::statements(),
            ...self::package(),
            ...self::enums(),
            ...self::errors(),
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

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.INTERCOMPANY' => ['Infragruppo e consolidamento', 'Intercompany and consolidation', 'Konzerninterne Geschäfte und Konsolidierung', 'Intragroupe et consolidation'],
            'NAV.INTERCOMPANY_STRUCTURE' => ['Struttura del gruppo', 'Group structure', 'Konzernstruktur', 'Structure du groupe'],
            'NAV.INTERCOMPANY_TRANSACTIONS' => ['Scritture a specchio', 'Mirror postings', 'Spiegelbuchungen', 'Écritures miroir'],
            'NAV.INTERCOMPANY_RECONCILIATION' => ['Riconciliazione infragruppo', 'Intercompany reconciliation', 'Konzernabstimmung', 'Rapprochement intragroupe'],
            'NAV.INTERCOMPANY_CONSOLIDATION' => ['Esecuzione del consolidamento', 'Consolidation run', 'Konsolidierungslauf', 'Exécution de la consolidation'],
            'NAV.INTERCOMPANY_STATEMENTS' => ['Bilancio consolidato', 'Consolidated statements', 'Konzernabschluss', 'États consolidés'],
            'PAGE_TITLE.INTERCOMPANY_STRUCTURE' => ['Struttura del gruppo e partecipazioni', 'Group structure and ownership', 'Konzernstruktur und Beteiligungen', 'Structure du groupe et participations'],
            'PAGE_TITLE.INTERCOMPANY_TRANSACTIONS' => ['Operazioni infragruppo e scritture a specchio', 'Intercompany transactions and mirror postings', 'Konzerninterne Geschäfte und Spiegelbuchungen', 'Opérations intragroupe et écritures miroir'],
            'PAGE_TITLE.INTERCOMPANY_RECONCILIATION' => ['Riconciliazione delle partite reciproche', 'Reciprocal balance reconciliation', 'Abstimmung der gegenseitigen Salden', 'Rapprochement des soldes réciproques'],
            'PAGE_TITLE.INTERCOMPANY_CONSOLIDATION' => ['Consolidamento guidato ed elisioni', 'Guided consolidation and eliminations', 'Geführte Konsolidierung und Eliminierungen', 'Consolidation guidée et éliminations'],
            'PAGE_TITLE.INTERCOMPANY_STATEMENTS' => ['Bilancio consolidato e fascicolo', 'Consolidated statements and package', 'Konzernabschluss und Berichtspaket', 'États consolidés et dossier'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function common(): array
    {
        return [
            'intercompany.common.action' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'intercompany.common.all' => ['Tutti', 'All', 'Alle', 'Tous'],
            'intercompany.common.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'intercompany.common.asOfDate' => ['Alla data', 'As of date', 'Stichtag', 'À la date'],
            'intercompany.common.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'intercompany.common.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'intercompany.common.close' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
            'intercompany.common.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'intercompany.common.consolidated' => ['Consolidato', 'Consolidated', 'Konsolidiert', 'Consolidé'],
            'intercompany.common.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'intercompany.common.date' => ['Data', 'Date', 'Datum', 'Date'],
            'intercompany.common.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'intercompany.common.details' => ['Dettagli', 'Details', 'Details', 'Détails'],
            'intercompany.common.difference' => ['Differenza', 'Difference', 'Differenz', 'Écart'],
            'intercompany.common.download' => ['Scarica', 'Download', 'Herunterladen', 'Télécharger'],
            'intercompany.common.empty' => ['Nessun dato disponibile', 'No data available', 'Keine Daten vorhanden', 'Aucune donnée disponible'],
            'intercompany.common.entity' => ['Società', 'Entity', 'Gesellschaft', 'Société'],
            'intercompany.common.filter' => ['Filtra', 'Filter', 'Filtern', 'Filtrer'],
            'intercompany.common.group' => ['Gruppo', 'Group', 'Konzern', 'Groupe'],
            'intercompany.common.loading' => ['Caricamento…', 'Loading…', 'Wird geladen…', 'Chargement…'],
            'intercompany.common.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'intercompany.common.no' => ['No', 'No', 'Nein', 'Non'],
            'intercompany.common.note' => ['Nota', 'Note', 'Notiz', 'Note'],
            'intercompany.common.period' => ['Periodo fiscale', 'Fiscal period', 'Geschäftsperiode', 'Période fiscale'],
            'intercompany.common.reason' => ['Motivo', 'Reason', 'Grund', 'Motif'],
            'intercompany.common.reference' => ['Riferimento', 'Reference', 'Referenz', 'Référence'],
            'intercompany.common.refresh' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'intercompany.common.run' => ['Esegui', 'Run', 'Ausführen', 'Exécuter'],
            'intercompany.common.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'intercompany.common.standard' => ['Standard contabile', 'Accounting standard', 'Rechnungslegungsstandard', 'Référentiel comptable'],
            'intercompany.common.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'intercompany.common.total' => ['Totale', 'Total', 'Total', 'Total'],
            'intercompany.common.version' => ['Versione', 'Version', 'Version', 'Version'],
            'intercompany.common.yes' => ['Sì', 'Yes', 'Ja', 'Oui'],
            'intercompany.common.staleVersion' => ['I dati sono stati modificati da un altro utente: ricarica e riprova.', 'The data was changed by another user: reload and try again.', 'Die Daten wurden von einem anderen Benutzer geändert: Bitte neu laden und erneut versuchen.', 'Les données ont été modifiées par un autre utilisateur : rechargez et réessayez.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function structure(): array
    {
        return [
            'intercompany.structure.title' => ['Struttura del gruppo', 'Group structure', 'Konzernstruktur', 'Structure du groupe'],
            'intercompany.structure.settings' => ['Impostazioni infragruppo', 'Intercompany settings', 'Konzerninterne Einstellungen', 'Paramètres intragroupe'],
            'intercompany.structure.mirrorMode' => ['Modalità della scrittura a specchio', 'Mirror posting mode', 'Modus der Spiegelbuchung', 'Mode de l’écriture miroir'],
            'intercompany.structure.mirrorModeHelp' => ['Bozza: la fattura passiva resta da registrare. Confermata: viene registrata subito nei libri della consorella.', 'Draft: the supplier invoice is left to be posted. Confirmed: it is posted at once in the sister company’s books.', 'Entwurf: Die Lieferantenrechnung bleibt zu buchen. Bestätigt: Sie wird sofort in den Büchern der Schwestergesellschaft gebucht.', 'Brouillon : la facture fournisseur reste à comptabiliser. Confirmée : elle est comptabilisée tout de suite dans les livres de la société sœur.'],
            'intercompany.structure.autoMirror' => ['Genera automaticamente alla registrazione', 'Generate automatically when posted', 'Beim Buchen automatisch erzeugen', 'Générer automatiquement à la comptabilisation'],
            'intercompany.structure.syncPayments' => ['Sincronizza i pagamenti', 'Synchronise payments', 'Zahlungen synchronisieren', 'Synchroniser les paiements'],
            'intercompany.structure.nettingEnabled' => ['Consenti la compensazione delle partite', 'Allow netting of open items', 'Verrechnung offener Posten erlauben', 'Autoriser la compensation des partites'],
            'intercompany.structure.amountTolerance' => ['Tolleranza sugli importi', 'Amount tolerance', 'Betragstoleranz', 'Tolérance sur les montants'],
            'intercompany.structure.dateToleranceDays' => ['Tolleranza sulle date (giorni)', 'Date tolerance (days)', 'Datumstoleranz (Tage)', 'Tolérance sur les dates (jours)'],
            'intercompany.structure.defaultStandard' => ['Standard di redazione predefinito', 'Default reporting standard', 'Standard-Rechnungslegungsstandard', 'Référentiel d’établissement par défaut'],
            'intercompany.structure.settingsSaved' => ['Impostazioni salvate', 'Settings saved', 'Einstellungen gespeichert', 'Paramètres enregistrés'],
            'intercompany.structure.partyLinks' => ['Anagrafiche delle società del gruppo', 'Group entity contacts', 'Kontakte der Konzerngesellschaften', 'Contacts des sociétés du groupe'],
            'intercompany.structure.partyLinksHelp' => ['Collega ogni società del gruppo al cliente o fornitore che la rappresenta nei libri delle altre società.', 'Link each group entity to the customer or supplier that stands for it in the books of the other entities.', 'Verknüpfen Sie jede Konzerngesellschaft mit dem Kunden oder Lieferanten, der sie in den Büchern der anderen Gesellschaften vertritt.', 'Reliez chaque société du groupe au client ou fournisseur qui la représente dans les livres des autres sociétés.'],
            'intercompany.structure.addPartyLink' => ['Aggiungi collegamento', 'Add link', 'Verknüpfung hinzufügen', 'Ajouter un lien'],
            'intercompany.structure.entityBooks' => ['Libri della società', 'Entity books', 'Bücher der Gesellschaft', 'Livres de la société'],
            'intercompany.structure.counterparty' => ['Società rappresentata', 'Entity represented', 'Vertretene Gesellschaft', 'Société représentée'],
            'intercompany.structure.contact' => ['Cliente o fornitore', 'Customer or supplier', 'Kunde oder Lieferant', 'Client ou fournisseur'],
            'intercompany.structure.role' => ['Ruolo', 'Role', 'Rolle', 'Rôle'],
            'intercompany.structure.ownershipMatrix' => ['Matrice delle partecipazioni', 'Ownership matrix', 'Beteiligungsmatrix', 'Matrice des participations'],
            'intercompany.structure.direct' => ['Quota diretta', 'Direct share', 'Direkter Anteil', 'Part directe'],
            'intercompany.structure.effective' => ['Quota effettiva', 'Effective share', 'Effektiver Anteil', 'Part effective'],
            'intercompany.structure.voting' => ['Diritti di voto', 'Voting rights', 'Stimmrechte', 'Droits de vote'],
            'intercompany.structure.method' => ['Metodo di consolidamento', 'Consolidation method', 'Konsolidierungsmethode', 'Méthode de consolidation'],
            'intercompany.structure.periodRates' => ['Tassi di cambio di consolidamento', 'Consolidation exchange rates', 'Konsolidierungskurse', 'Taux de change de consolidation'],
            'intercompany.structure.periodRatesHelp' => ['Cambio di chiusura (stato patrimoniale), medio (conto economico) e storico (patrimonio netto). Prevalgono sui cambi pubblicati.', 'Closing rate (balance sheet), average rate (income statement) and historical rate (equity). They take precedence over the published rates.', 'Stichtagskurs (Bilanz), Durchschnittskurs (Erfolgsrechnung) und historischer Kurs (Eigenkapital). Sie haben Vorrang vor den veröffentlichten Kursen.', 'Cours de clôture (bilan), cours moyen (compte de résultat) et cours historique (capitaux propres). Ils priment sur les cours publiés.'],
            'intercompany.structure.addRate' => ['Fissa un cambio', 'Fix a rate', 'Kurs festlegen', 'Fixer un taux'],
            'intercompany.structure.rate' => ['Cambio', 'Rate', 'Kurs', 'Taux'],
            'intercompany.structure.rateType' => ['Tipo di cambio', 'Rate type', 'Kursart', 'Type de taux'],
            'intercompany.structure.periodEnd' => ['Fine periodo', 'Period end', 'Periodenende', 'Fin de période'],
            'intercompany.structure.scopeValidation' => ['Verifica dell’area di consolidamento', 'Scope validation', 'Prüfung des Konsolidierungskreises', 'Validation du périmètre'],
            'intercompany.structure.scopeReady' => ['L’area di consolidamento è pronta', 'The consolidation scope is ready', 'Der Konsolidierungskreis ist bereit', 'Le périmètre de consolidation est prêt'],
            'intercompany.structure.scopeNotReady' => ['L’area di consolidamento ha controlli non superati', 'The consolidation scope has failed checks', 'Der Konsolidierungskreis hat nicht bestandene Prüfungen', 'Le périmètre de consolidation comporte des contrôles en échec'],
            'intercompany.structure.check.has_parent' => ['Società capogruppo definita', 'Parent entity defined', 'Muttergesellschaft definiert', 'Société mère définie'],
            'intercompany.structure.check.has_members' => ['Almeno una controllata o collegata', 'At least one subsidiary or associate', 'Mindestens eine Tochter- oder Beteiligungsgesellschaft', 'Au moins une filiale ou entreprise associée'],
            'intercompany.structure.check.ownership_valid' => ['Percentuale di partecipazione valida', 'Valid ownership percentage', 'Gültiger Beteiligungsprozentsatz', 'Pourcentage de participation valide'],
            'intercompany.structure.check.holder_in_group' => ['Detentore della partecipazione nel gruppo', 'Holder of the interest is in the group', 'Inhaber der Beteiligung gehört zum Konzern', 'Détenteur de la participation dans le groupe'],
            'intercompany.structure.check.currency_defined' => ['Valuta contabile definita', 'Accounting currency defined', 'Buchhaltungswährung definiert', 'Devise comptable définie'],
            'intercompany.structure.check.rate_available' => ['Cambio di chiusura disponibile', 'Closing rate available', 'Stichtagskurs verfügbar', 'Cours de clôture disponible'],
            'intercompany.structure.check.trial_balance_ready' => ['Bilancio di verifica disponibile', 'Trial balance available', 'Saldenliste verfügbar', 'Balance de vérification disponible'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function transactions(): array
    {
        return [
            'intercompany.transactions.title' => ['Operazioni infragruppo', 'Intercompany transactions', 'Konzerninterne Geschäfte', 'Opérations intragroupe'],
            'intercompany.transactions.scan' => ['Cerca documenti da specchiare', 'Find documents to mirror', 'Zu spiegelnde Belege suchen', 'Rechercher les documents à refléter'],
            'intercompany.transactions.scanResult' => ['Creati: {created}, già presenti: {skipped}, falliti: {failed}', 'Created: {created}, already present: {skipped}, failed: {failed}', 'Erstellt: {created}, bereits vorhanden: {skipped}, fehlgeschlagen: {failed}', 'Créés : {created}, déjà présents : {skipped}, en échec : {failed}'],
            'intercompany.transactions.mirror' => ['Genera la scrittura a specchio', 'Generate the mirror posting', 'Spiegelbuchung erzeugen', 'Générer l’écriture miroir'],
            'intercompany.transactions.retry' => ['Riprova', 'Retry', 'Erneut versuchen', 'Réessayer'],
            'intercompany.transactions.confirm' => ['Conferma la registrazione', 'Confirm the posting', 'Buchung bestätigen', 'Confirmer la comptabilisation'],
            'intercompany.transactions.reverse' => ['Storna', 'Reverse', 'Stornieren', 'Contre-passer'],
            'intercompany.transactions.reverseReason' => ['Motivo dello storno', 'Reason for the reversal', 'Grund der Stornierung', 'Motif de la contre-passation'],
            'intercompany.transactions.syncPayment' => ['Sincronizza il pagamento', 'Synchronise the payment', 'Zahlung synchronisieren', 'Synchroniser le paiement'],
            'intercompany.transactions.sourceDocument' => ['Documento di origine', 'Source document', 'Ursprungsbeleg', 'Document d’origine'],
            'intercompany.transactions.mirrorDocument' => ['Documento a specchio', 'Mirror document', 'Spiegelbeleg', 'Document miroir'],
            'intercompany.transactions.issuer' => ['Società emittente', 'Issuing entity', 'Ausstellende Gesellschaft', 'Société émettrice'],
            'intercompany.transactions.recipient' => ['Società destinataria', 'Receiving entity', 'Empfangende Gesellschaft', 'Société destinataire'],
            'intercompany.transactions.documentType' => ['Tipo di documento', 'Document type', 'Belegart', 'Type de document'],
            'intercompany.transactions.mirrorStatus' => ['Stato della registrazione', 'Posting status', 'Buchungsstatus', 'Statut de comptabilisation'],
            'intercompany.transactions.paymentStatus' => ['Stato del pagamento', 'Payment status', 'Zahlungsstatus', 'Statut du paiement'],
            'intercompany.transactions.paidAmount' => ['Importo pagato', 'Paid amount', 'Bezahlter Betrag', 'Montant payé'],
            'intercompany.transactions.variance' => ['Scostamento', 'Variance', 'Abweichung', 'Écart'],
            'intercompany.transactions.net' => ['Imponibile', 'Net', 'Netto', 'Net'],
            'intercompany.transactions.tax' => ['Imposta', 'Tax', 'Steuer', 'Taxe'],
            'intercompany.transactions.gross' => ['Totale', 'Gross', 'Brutto', 'Brut'],
            'intercompany.transactions.events' => ['Registro delle sincronizzazioni', 'Synchronisation log', 'Synchronisationsprotokoll', 'Journal de synchronisation'],
            'intercompany.transactions.noMirror' => ['Nessuna scrittura a specchio', 'No mirror posting yet', 'Noch keine Spiegelbuchung', 'Pas encore d’écriture miroir'],
            'intercompany.transactions.summary.total' => ['Operazioni', 'Transactions', 'Geschäfte', 'Opérations'],
            'intercompany.transactions.summary.drafted' => ['In bozza', 'Drafted', 'Im Entwurf', 'En brouillon'],
            'intercompany.transactions.summary.confirmed' => ['Confermate', 'Confirmed', 'Bestätigt', 'Confirmées'],
            'intercompany.transactions.summary.failed' => ['Fallite', 'Failed', 'Fehlgeschlagen', 'En échec'],
            'intercompany.transactions.summary.unpaid' => ['Da incassare', 'Unpaid', 'Unbezahlt', 'Impayées'],
            'intercompany.transactions.confirmed' => ['Scrittura confermata', 'Posting confirmed', 'Buchung bestätigt', 'Écriture confirmée'],
            'intercompany.transactions.reversed' => ['Scrittura stornata', 'Posting reversed', 'Buchung storniert', 'Écriture contre-passée'],
            'intercompany.transactions.synced' => ['Pagamento sincronizzato', 'Payment synchronised', 'Zahlung synchronisiert', 'Paiement synchronisé'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function netting(): array
    {
        return [
            'intercompany.netting.title' => ['Compensazione delle partite', 'Netting of open items', 'Verrechnung offener Posten', 'Compensation des partites'],
            'intercompany.netting.propose' => ['Proponi compensazione', 'Propose netting', 'Verrechnung vorschlagen', 'Proposer une compensation'],
            'intercompany.netting.entityA' => ['Società A', 'Entity A', 'Gesellschaft A', 'Société A'],
            'intercompany.netting.entityB' => ['Società B', 'Entity B', 'Gesellschaft B', 'Société B'],
            'intercompany.netting.receivable' => ['Crediti di A verso B', 'Receivables of A from B', 'Forderungen von A gegen B', 'Créances de A sur B'],
            'intercompany.netting.payable' => ['Crediti di B verso A', 'Receivables of B from A', 'Forderungen von B gegen A', 'Créances de B sur A'],
            'intercompany.netting.net' => ['Saldo netto', 'Net balance', 'Nettosaldo', 'Solde net'],
            'intercompany.netting.direction' => ['Chi paga', 'Who pays', 'Wer zahlt', 'Qui paie'],
            'intercompany.netting.direction.a_pays_b' => ['A paga B', 'A pays B', 'A zahlt B', 'A paie B'],
            'intercompany.netting.direction.b_pays_a' => ['B paga A', 'B pays A', 'B zahlt A', 'B paie A'],
            'intercompany.netting.direction.balanced' => ['Pareggiato', 'Balanced', 'Ausgeglichen', 'Équilibré'],
            'intercompany.netting.fxVariance' => ['Differenza cambio', 'FX variance', 'Kursdifferenz', 'Écart de change'],
            'intercompany.netting.amountVariance' => ['Differenza di importo', 'Amount variance', 'Betragsdifferenz', 'Écart de montant'],
            'intercompany.netting.items' => ['Partite compensate', 'Netted items', 'Verrechnete Posten', 'Partites compensées'],
            'intercompany.netting.side' => ['Lato', 'Side', 'Seite', 'Côté'],
            'intercompany.netting.openAmount' => ['Importo aperto', 'Open amount', 'Offener Betrag', 'Montant ouvert'],
            'intercompany.netting.appliedAmount' => ['Importo compensato', 'Netted amount', 'Verrechneter Betrag', 'Montant compensé'],
            'intercompany.netting.fxRate' => ['Cambio applicato', 'Applied rate', 'Angewandter Kurs', 'Taux appliqué'],
            'intercompany.netting.confirm' => ['Conferma e applica', 'Confirm and apply', 'Bestätigen und anwenden', 'Confirmer et appliquer'],
            'intercompany.netting.cancel' => ['Annulla la compensazione', 'Cancel the netting', 'Verrechnung abbrechen', 'Annuler la compensation'],
            'intercompany.netting.cancelReason' => ['Motivo dell’annullamento', 'Reason for cancelling', 'Grund des Abbruchs', 'Motif de l’annulation'],
            'intercompany.netting.confirmed' => ['Compensazione applicata', 'Netting applied', 'Verrechnung angewendet', 'Compensation appliquée'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function reconciliation(): array
    {
        return [
            'intercompany.reconciliation.title' => ['Riconciliazione partite reciproche', 'Reciprocal balance reconciliation', 'Abstimmung der gegenseitigen Salden', 'Rapprochement des soldes réciproques'],
            'intercompany.reconciliation.run' => ['Esegui riconciliazione', 'Run reconciliation', 'Abstimmung ausführen', 'Lancer le rapprochement'],
            'intercompany.reconciliation.autoMatch' => ['Abbinamento automatico', 'Automatic matching', 'Automatischer Abgleich', 'Rapprochement automatique'],
            'intercompany.reconciliation.matrix' => ['Matrice di quadratura dei saldi reciproci', 'Reciprocal balance quadrature matrix', 'Abstimmungsmatrix der gegenseitigen Salden', 'Matrice de quadrature des soldes réciproques'],
            'intercompany.reconciliation.receivableA' => ['Crediti', 'Receivables', 'Forderungen', 'Créances'],
            'intercompany.reconciliation.payableB' => ['Debiti', 'Payables', 'Verbindlichkeiten', 'Dettes'],
            'intercompany.reconciliation.cellMatched' => ['Quadrato', 'Matched', 'Abgestimmt', 'Équilibré'],
            'intercompany.reconciliation.cellDifference' => ['Disallineato', 'Out of balance', 'Abweichend', 'Désaligné'],
            'intercompany.reconciliation.openItems' => ['Partite aperte', 'Open items', 'Offene Posten', 'Partites ouvertes'],
            'intercompany.reconciliation.drillDown' => ['Apri il dettaglio delle partite', 'Open the item detail', 'Posten-Detail öffnen', 'Ouvrir le détail des partites'],
            'intercompany.reconciliation.batches' => ['Riconciliazioni eseguite', 'Reconciliations run', 'Durchgeführte Abstimmungen', 'Rapprochements effectués'],
            'intercompany.reconciliation.items' => ['Partite', 'Items', 'Posten', 'Partites'],
            'intercompany.reconciliation.anomaly' => ['Anomalia', 'Anomaly', 'Anomalie', 'Anomalie'],
            'intercompany.reconciliation.anomalyReason' => ['Causa', 'Cause', 'Ursache', 'Cause'],
            'intercompany.reconciliation.documentA' => ['Documento della società A', 'Entity A document', 'Beleg der Gesellschaft A', 'Document de la société A'],
            'intercompany.reconciliation.documentB' => ['Documento della società B', 'Entity B document', 'Beleg der Gesellschaft B', 'Document de la société B'],
            'intercompany.reconciliation.differenceGroup' => ['Differenza in valuta di gruppo', 'Difference in group currency', 'Differenz in Konzernwährung', 'Écart en devise du groupe'],
            'intercompany.reconciliation.matchKind' => ['Tipo di abbinamento', 'Match type', 'Abgleichsart', 'Type de rapprochement'],
            'intercompany.reconciliation.match' => ['Abbina', 'Match', 'Abgleichen', 'Rapprocher'],
            'intercompany.reconciliation.counterpart' => ['Documento della controparte', 'Counterpart document', 'Beleg der Gegenpartei', 'Document de la contrepartie'],
            'intercompany.reconciliation.unmatch' => ['Annulla abbinamento', 'Unmatch', 'Abgleich aufheben', 'Annuler le rapprochement'],
            'intercompany.reconciliation.dispute' => ['Contesta', 'Dispute', 'Beanstanden', 'Contester'],
            'intercompany.reconciliation.writeOff' => ['Stralcia', 'Write off', 'Ausbuchen', 'Passer en perte'],
            'intercompany.reconciliation.adjust' => ['Rettifica', 'Adjust', 'Anpassen', 'Ajuster'],
            'intercompany.reconciliation.adjustmentAmount' => ['Importo della rettifica', 'Adjustment amount', 'Anpassungsbetrag', 'Montant de l’ajustement'],
            'intercompany.reconciliation.resolution' => ['Motivazione della risoluzione', 'Resolution reason', 'Begründung der Lösung', 'Motif de la résolution'],
            'intercompany.reconciliation.totals.items' => ['Partite', 'Items', 'Posten', 'Partites'],
            'intercompany.reconciliation.totals.matched' => ['Abbinate', 'Matched', 'Abgeglichen', 'Rapprochées'],
            'intercompany.reconciliation.totals.unmatched' => ['Non abbinate', 'Unmatched', 'Nicht abgeglichen', 'Non rapprochées'],
            'intercompany.reconciliation.kind.exact' => ['Esatto', 'Exact', 'Exakt', 'Exact'],
            'intercompany.reconciliation.kind.tolerance' => ['Entro tolleranza', 'Within tolerance', 'Innerhalb der Toleranz', 'Dans la tolérance'],
            'intercompany.reconciliation.kind.manual' => ['Manuale', 'Manual', 'Manuell', 'Manuel'],
            'intercompany.reconciliation.reason.in_transit' => ['Documento in transito', 'Document in transit', 'Beleg unterwegs', 'Document en transit'],
            'intercompany.reconciliation.reason.missing_in_counterparty' => ['Non registrato dalla controparte', 'Not booked by the counterparty', 'Von der Gegenpartei nicht gebucht', 'Non comptabilisé par la contrepartie'],
            'intercompany.reconciliation.reason.missing_in_issuer' => ['Non presente nella società emittente', 'Not found in the issuing entity', 'In der ausstellenden Gesellschaft nicht vorhanden', 'Absent de la société émettrice'],
            'intercompany.reconciliation.reason.currency_mismatch' => ['Valute diverse tra i due documenti', 'The two documents are in different currencies', 'Die beiden Belege lauten auf verschiedene Währungen', 'Les deux documents sont en devises différentes'],
            'intercompany.reconciliation.reason.rate_difference' => ['Cambi diversi tra i due documenti', 'The two documents use different exchange rates', 'Die beiden Belege verwenden unterschiedliche Wechselkurse', 'Les deux documents utilisent des taux différents'],
            'intercompany.reconciliation.reason.tax_difference' => ['Imposta (IVA) diversa', 'Different tax (VAT)', 'Abweichende Steuer (MWST)', 'Taxe (TVA) différente'],
            'intercompany.reconciliation.reason.amount_difference' => ['Importo diverso', 'Different amount', 'Abweichender Betrag', 'Montant différent'],
            'intercompany.reconciliation.reason.mirror_draft' => ['Scrittura a specchio ancora in bozza', 'Mirror posting still a draft', 'Spiegelbuchung noch im Entwurf', 'Écriture miroir encore en brouillon'],
            'intercompany.reconciliation.reason.booking_date_gap' => ['Date di registrazione distanti', 'Booking dates too far apart', 'Buchungsdaten liegen zu weit auseinander', 'Dates de comptabilisation trop éloignées'],
            'intercompany.reconciliation.reason.payment_lag' => ['Pagamento non ancora allineato', 'Payment not yet aligned', 'Zahlung noch nicht abgeglichen', 'Paiement pas encore aligné'],
            'intercompany.reconciliation.reason.unmatched_by_user' => ['Abbinamento annullato manualmente', 'Match undone by hand', 'Abgleich manuell aufgehoben', 'Rapprochement annulé manuellement'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function run(): array
    {
        return [
            'intercompany.run.title' => ['Consolidamento guidato', 'Guided consolidation', 'Geführte Konsolidierung', 'Consolidation guidée'],
            'intercompany.run.workflow' => ['Avanzamento del consolidamento', 'Consolidation progress', 'Fortschritt der Konsolidierung', 'Avancement de la consolidation'],
            'intercompany.run.step.draft' => ['Perimetro e albero', 'Scope and tree', 'Kreis und Struktur', 'Périmètre et arbre'],
            'intercompany.run.step.calculating' => ['Conversione e calcolo', 'Translation and calculation', 'Umrechnung und Berechnung', 'Conversion et calcul'],
            'intercompany.run.step.review' => ['Revisione delle elisioni', 'Review of eliminations', 'Prüfung der Eliminierungen', 'Revue des éliminations'],
            'intercompany.run.step.locked' => ['Blocco', 'Lock', 'Sperre', 'Verrouillage'],
            'intercompany.run.step.posted' => ['Pubblicazione', 'Publication', 'Veröffentlichung', 'Publication'],
            'intercompany.run.advance' => ['Avanza', 'Advance', 'Weiter', 'Avancer'],
            'intercompany.run.advanceTo' => ['Passa a: {status}', 'Move to: {status}', 'Weiter zu: {status}', 'Passer à : {status}'],
            'intercompany.run.reopen' => ['Riapri', 'Reopen', 'Wieder öffnen', 'Rouvrir'],
            'intercompany.run.reopenReason' => ['Motivo della riapertura (almeno 10 caratteri)', 'Reason for reopening (at least 10 characters)', 'Grund der Wiedereröffnung (mindestens 10 Zeichen)', 'Motif de la réouverture (10 caractères minimum)'],
            'intercompany.run.locked' => ['Il consolidamento è bloccato: riaprilo con un motivo per modificarlo.', 'The consolidation is locked: reopen it with a reason to change it.', 'Die Konsolidierung ist gesperrt: Zum Ändern mit Begründung wieder öffnen.', 'La consolidation est verrouillée : rouvrez-la avec un motif pour la modifier.'],
            'intercompany.run.history' => ['Cronologia e audit', 'History and audit', 'Verlauf und Prüfpfad', 'Historique et audit'],
            'intercompany.run.action.advance' => ['Avanzamento', 'Advance', 'Fortschritt', 'Avancement'],
            'intercompany.run.action.lock' => ['Blocco', 'Lock', 'Sperre', 'Verrouillage'],
            'intercompany.run.action.post' => ['Pubblicazione', 'Publication', 'Veröffentlichung', 'Publication'],
            'intercompany.run.action.reopen' => ['Riapertura', 'Reopening', 'Wiedereröffnung', 'Réouverture'],
            'intercompany.run.translation' => ['Conversione dei bilanci', 'Translation of the statements', 'Umrechnung der Abschlüsse', 'Conversion des comptes'],
            'intercompany.run.translationReserve' => ['Riserva di conversione', 'Currency translation reserve', 'Währungsumrechnungsreserve', 'Réserve de conversion'],
            'intercompany.run.closingRate' => ['Cambio di chiusura', 'Closing rate', 'Stichtagskurs', 'Cours de clôture'],
            'intercompany.run.averageRate' => ['Cambio medio', 'Average rate', 'Durchschnittskurs', 'Cours moyen'],
            'intercompany.run.historicalRate' => ['Cambio storico', 'Historical rate', 'Historischer Kurs', 'Cours historique'],
            'intercompany.run.eliminationLedger' => ['Registro delle elisioni e rettifiche', 'Elimination and adjustment ledger', 'Eliminierungs- und Anpassungsjournal', 'Journal des éliminations et ajustements'],
            'intercompany.run.entry' => ['Scrittura', 'Entry', 'Buchung', 'Écriture'],
            'intercompany.run.line' => ['Riga', 'Line', 'Zeile', 'Ligne'],
            'intercompany.run.account' => ['Conto', 'Account', 'Konto', 'Compte'],
            'intercompany.run.debit' => ['Dare', 'Debit', 'Soll', 'Débit'],
            'intercompany.run.credit' => ['Avere', 'Credit', 'Haben', 'Crédit'],
            'intercompany.run.preElimination' => ['Prima dell’elisione', 'Before elimination', 'Vor der Eliminierung', 'Avant élimination'],
            'intercompany.run.postElimination' => ['Dopo l’elisione', 'After elimination', 'Nach der Eliminierung', 'Après élimination'],
            'intercompany.run.totalsByType' => ['Totali per tipo di elisione', 'Totals by elimination type', 'Summen nach Eliminierungsart', 'Totaux par type d’élimination'],
            'intercompany.run.manualAdjustments' => ['Rettifiche manuali', 'Manual adjustments', 'Manuelle Anpassungen', 'Ajustements manuels'],
            'intercompany.run.addManualAdjustment' => ['Aggiungi rettifica manuale', 'Add manual adjustment', 'Manuelle Anpassung hinzufügen', 'Ajouter un ajustement manuel'],
            'intercompany.run.adjustmentReference' => ['Riferimento del documento', 'Document reference', 'Belegreferenz', 'Référence du document'],
            'intercompany.run.adjustmentReason' => ['Motivo (almeno 10 caratteri)', 'Reason (at least 10 characters)', 'Grund (mindestens 10 Zeichen)', 'Motif (10 caractères minimum)'],
            'intercompany.run.adjustmentBalanced' => ['Dare e avere devono coincidere', 'Debits and credits must be equal', 'Soll und Haben müssen übereinstimmen', 'Le débit et le crédit doivent être égaux'],
            'intercompany.run.accountType' => ['Tipo di conto', 'Account type', 'Kontotyp', 'Type de compte'],
            'intercompany.run.void' => ['Annulla la rettifica', 'Void the adjustment', 'Anpassung stornieren', 'Annuler l’ajustement'],
            'intercompany.run.nci' => ['Quote di terzi', 'Non-controlling interests', 'Minderheitsanteile', 'Intérêts minoritaires'],
            'intercompany.run.nciPercentage' => ['Quota di terzi', 'NCI share', 'Minderheitsanteil', 'Part des minoritaires'],
            'intercompany.run.nciOpening' => ['Quota di terzi iniziale', 'Opening NCI', 'Minderheitsanteile zu Beginn', 'Intérêts minoritaires d’ouverture'],
            'intercompany.run.nciProfit' => ['Risultato di pertinenza di terzi', 'Profit attributable to NCI', 'Auf Minderheiten entfallender Gewinn', 'Résultat revenant aux minoritaires'],
            'intercompany.run.nciNetAssets' => ['Patrimonio netto di terzi', 'NCI in net assets', 'Minderheitsanteile am Eigenkapital', 'Intérêts minoritaires dans l’actif net'],
            'intercompany.run.shareholdings' => ['Prospetto delle partecipazioni e area di consolidamento', 'Schedule of shareholdings and consolidation scope', 'Beteiligungsübersicht und Konsolidierungskreis', 'Tableau des participations et périmètre de consolidation'],
            'intercompany.run.consolidationShare' => ['Quota consolidata', 'Consolidated share', 'Konsolidierter Anteil', 'Part consolidée'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function statements(): array
    {
        return [
            'intercompany.statements.title' => ['Bilancio consolidato', 'Consolidated statements', 'Konzernabschluss', 'États consolidés'],
            'intercompany.statements.balanceSheet' => ['Stato patrimoniale consolidato', 'Consolidated balance sheet', 'Konsolidierte Bilanz', 'Bilan consolidé'],
            'intercompany.statements.incomeStatement' => ['Conto economico consolidato', 'Consolidated income statement', 'Konsolidierte Erfolgsrechnung', 'Compte de résultat consolidé'],
            'intercompany.statements.cashFlow' => ['Rendiconto finanziario consolidato', 'Consolidated cash flow statement', 'Konsolidierte Geldflussrechnung', 'Tableau de flux de trésorerie consolidé'],
            'intercompany.statements.standalone' => ['Somma delle società', 'Sum of the entities', 'Summe der Gesellschaften', 'Somme des sociétés'],
            'intercompany.statements.eliminations' => ['Elisioni e rettifiche', 'Eliminations and adjustments', 'Eliminierungen und Anpassungen', 'Éliminations et ajustements'],
            'intercompany.statements.consolidated' => ['Consolidato', 'Consolidated', 'Konsolidiert', 'Consolidé'],
            'intercompany.statements.section.assets' => ['Attivo', 'Assets', 'Aktiven', 'Actif'],
            'intercompany.statements.section.liabilities' => ['Debiti e passività', 'Liabilities', 'Verbindlichkeiten', 'Dettes'],
            'intercompany.statements.section.equity' => ['Patrimonio netto', 'Equity', 'Eigenkapital', 'Capitaux propres'],
            'intercompany.statements.section.revenue' => ['Ricavi', 'Revenue', 'Ertrag', 'Produits'],
            'intercompany.statements.section.cogs' => ['Costo del venduto', 'Cost of sales', 'Warenaufwand', 'Coût des ventes'],
            'intercompany.statements.section.operating_expenses' => ['Costi operativi', 'Operating expenses', 'Betriebsaufwand', 'Charges d’exploitation'],
            'intercompany.statements.section.value_of_production' => ['Valore della produzione', 'Value of production', 'Produktionswert', 'Valeur de la production'],
            'intercompany.statements.section.cost_of_production' => ['Costi della produzione', 'Costs of production', 'Produktionskosten', 'Coûts de production'],
            'intercompany.statements.totalAssets' => ['Totale attivo', 'Total assets', 'Total Aktiven', 'Total de l’actif'],
            'intercompany.statements.totalLiabilities' => ['Totale debiti e passività', 'Total liabilities', 'Total Verbindlichkeiten', 'Total des dettes'],
            'intercompany.statements.totalEquity' => ['Totale patrimonio netto', 'Total equity', 'Total Eigenkapital', 'Total des capitaux propres'],
            'intercompany.statements.totalLiabilitiesAndEquity' => ['Totale passivo e patrimonio netto', 'Total liabilities and equity', 'Total Passiven', 'Total du passif et des capitaux propres'],
            'intercompany.statements.equityOwners' => ['Patrimonio netto di gruppo', 'Equity attributable to owners', 'Eigenkapital der Aktionäre', 'Capitaux propres part du groupe'],
            'intercompany.statements.result' => ['Risultato del periodo', 'Result of the period', 'Ergebnis der Periode', 'Résultat de la période'],
            'intercompany.statements.grossProfit' => ['Margine lordo', 'Gross profit', 'Bruttogewinn', 'Marge brute'],
            'intercompany.statements.incomeTax' => ['Imposte sul reddito', 'Income tax', 'Ertragssteuern', 'Impôts sur le résultat'],
            'intercompany.statements.netIncome' => ['Risultato netto', 'Net income', 'Reingewinn', 'Résultat net'],
            'intercompany.statements.netIncomeOwners' => ['Risultato di pertinenza del gruppo', 'Net income attributable to owners', 'Reingewinn der Aktionäre', 'Résultat net part du groupe'],
            'intercompany.statements.netIncomeNci' => ['Risultato di pertinenza di terzi', 'Net income attributable to NCI', 'Reingewinn der Minderheiten', 'Résultat net des minoritaires'],
            'intercompany.statements.isBalanced' => ['Lo stato patrimoniale quadra', 'The balance sheet balances', 'Die Bilanz ist ausgeglichen', 'Le bilan est équilibré'],
            'intercompany.statements.notBalanced' => ['Lo stato patrimoniale non quadra', 'The balance sheet does not balance', 'Die Bilanz ist nicht ausgeglichen', 'Le bilan n’est pas équilibré'],
            'intercompany.statements.cashflow.operating' => ['Flusso da attività operativa', 'Cash flow from operating activities', 'Geldfluss aus Betriebstätigkeit', 'Flux de trésorerie d’exploitation'],
            'intercompany.statements.cashflow.investing' => ['Flusso da attività di investimento', 'Cash flow from investing activities', 'Geldfluss aus Investitionstätigkeit', 'Flux de trésorerie d’investissement'],
            'intercompany.statements.cashflow.financing' => ['Flusso da attività di finanziamento', 'Cash flow from financing activities', 'Geldfluss aus Finanzierungstätigkeit', 'Flux de trésorerie de financement'],
            'intercompany.statements.cashflow.fx_effect' => ['Effetto della conversione', 'Effect of translation', 'Effekt der Umrechnung', 'Effet de la conversion'],
            'intercompany.statements.cashflow.net_change' => ['Variazione netta della liquidità', 'Net change in cash', 'Nettoveränderung der flüssigen Mittel', 'Variation nette de la trésorerie'],
            'intercompany.statements.cashflow.opening_cash' => ['Liquidità iniziale', 'Opening cash', 'Flüssige Mittel zu Beginn', 'Trésorerie d’ouverture'],
            'intercompany.statements.cashflow.closing_cash' => ['Liquidità finale', 'Closing cash', 'Flüssige Mittel am Ende', 'Trésorerie de clôture'],
            'intercompany.statements.cashflow.unreconciled' => ['Differenza non riconciliata', 'Unreconciled difference', 'Nicht abgestimmte Differenz', 'Écart non rapproché'],
            'intercompany.statements.cashflow.noOpening' => ['Nessun consolidamento precedente: i saldi iniziali sono nulli.', 'No earlier consolidation: opening balances are nil.', 'Keine frühere Konsolidierung: Die Eröffnungssalden sind null.', 'Aucune consolidation antérieure : les soldes d’ouverture sont nuls.'],
            'intercompany.statements.cashflow.line.result_of_the_period' => ['Risultato del periodo', 'Result of the period', 'Ergebnis der Periode', 'Résultat de la période'],
            'intercompany.statements.cashflow.line.non_cash_expenses' => ['Costi non monetari (ammortamenti)', 'Non-cash expenses (depreciation)', 'Nicht liquiditätswirksamer Aufwand (Abschreibungen)', 'Charges non décaissées (amortissements)'],
            'intercompany.statements.cashflow.line.change_receivables' => ['Variazione dei crediti', 'Change in receivables', 'Veränderung der Forderungen', 'Variation des créances'],
            'intercompany.statements.cashflow.line.change_inventories' => ['Variazione delle rimanenze', 'Change in inventories', 'Veränderung der Vorräte', 'Variation des stocks'],
            'intercompany.statements.cashflow.line.change_other_current_assets' => ['Variazione di altre attività correnti', 'Change in other current assets', 'Veränderung übriger kurzfristiger Aktiven', 'Variation des autres actifs courants'],
            'intercompany.statements.cashflow.line.change_current_liabilities' => ['Variazione dei debiti correnti', 'Change in current liabilities', 'Veränderung kurzfristiger Verbindlichkeiten', 'Variation des dettes courantes'],
            'intercompany.statements.cashflow.line.change_unclassified' => ['Variazione di conti non classificati', 'Change in unclassified accounts', 'Veränderung nicht klassierter Konten', 'Variation des comptes non classés'],
            'intercompany.statements.cashflow.line.net_investment' => ['Investimenti netti', 'Net investment', 'Nettoinvestitionen', 'Investissements nets'],
            'intercompany.statements.cashflow.line.equity_movements' => ['Movimenti di patrimonio netto', 'Equity movements', 'Eigenkapitalbewegungen', 'Mouvements de capitaux propres'],
            'intercompany.statements.cashflow.line.non_current_liabilities' => ['Debiti a lungo termine', 'Non-current liabilities', 'Langfristige Verbindlichkeiten', 'Dettes non courantes'],
            'intercompany.statements.cashflow.line.translation_effect' => ['Effetto dei cambi di conversione', 'Translation effect', 'Umrechnungseffekt', 'Effet de conversion'],
            'intercompany.statements.fer30.translation_reserve' => ['Riserva di conversione (Swiss GAAP FER 30)', 'Translation reserve (Swiss GAAP FER 30)', 'Umrechnungsdifferenzen (Swiss GAAP FER 30)', 'Réserve de conversion (Swiss GAAP FER 30)'],
            'intercompany.statements.ifrs10.translation_reserve' => ['Riserva di conversione (IFRS 10)', 'Foreign currency translation reserve (IFRS 10)', 'Fremdwährungsumrechnungsreserve (IFRS 10)', 'Réserve de conversion (IFRS 10)'],
            'intercompany.statements.oic17.translation_reserve' => ['Riserva da differenze di traduzione (OIC 17)', 'Translation difference reserve (OIC 17)', 'Rücklage aus Umrechnungsdifferenzen (OIC 17)', 'Réserve de différences de conversion (OIC 17)'],
            'intercompany.statements.fer30.nci' => ['Quote di minoranza', 'Minority interests', 'Minderheitsanteile', 'Parts minoritaires'],
            'intercompany.statements.ifrs10.nci' => ['Partecipazioni di minoranza (NCI)', 'Non-controlling interests (NCI)', 'Nicht beherrschende Anteile (NCI)', 'Participations ne donnant pas le contrôle (NCI)'],
            'intercompany.statements.oic17.nci' => ['Patrimonio netto di terzi', 'Third-party equity', 'Eigenkapital Dritter', 'Capitaux propres des tiers'],
            'intercompany.statements.layout.function' => ['Schema per funzione', 'Function layout', 'Gliederung nach Funktion', 'Présentation par fonction'],
            'intercompany.statements.layout.nature' => ['Schema per natura', 'Nature layout', 'Gliederung nach Art', 'Présentation par nature'],
            'intercompany.statements.goodwill.offset_or_capitalise' => ['Avviamento: compensazione con il patrimonio netto o capitalizzazione', 'Goodwill: offset against equity or capitalise', 'Goodwill: Verrechnung mit dem Eigenkapital oder Aktivierung', 'Goodwill : imputation sur les capitaux propres ou inscription à l’actif'],
            'intercompany.statements.goodwill.capitalise_impairment_only' => ['Avviamento: capitalizzato, soggetto a verifica di perdita di valore', 'Goodwill: capitalised, tested for impairment', 'Goodwill: aktiviert, auf Wertminderung geprüft', 'Goodwill : inscrit à l’actif, test de dépréciation'],
            'intercompany.statements.goodwill.capitalise_amortise' => ['Avviamento: capitalizzato e ammortizzato', 'Goodwill: capitalised and amortised', 'Goodwill: aktiviert und abgeschrieben', 'Goodwill : inscrit à l’actif et amorti'],
            'intercompany.statements.standardNote' => ['Il rendiconto finanziario è redatto con il metodo indiretto.', 'The cash flow statement is prepared by the indirect method.', 'Die Geldflussrechnung wird nach der indirekten Methode erstellt.', 'Le tableau de flux de trésorerie est établi selon la méthode indirecte.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function package(): array
    {
        return [
            'intercompany.package.title' => ['Fascicolo di bilancio consolidato', 'Consolidated financial statement package', 'Konsolidiertes Berichtspaket', 'Dossier des comptes consolidés'],
            'intercompany.package.generate' => ['Genera il fascicolo', 'Generate the package', 'Berichtspaket erzeugen', 'Générer le dossier'],
            'intercompany.package.generatedAt' => ['Generato il', 'Generated on', 'Erstellt am', 'Généré le'],
            'intercompany.package.checksum' => ['Impronta di controllo', 'Checksum', 'Prüfsumme', 'Somme de contrôle'],
            'intercompany.package.download.json' => ['Scarica JSON', 'Download JSON', 'JSON herunterladen', 'Télécharger JSON'],
            'intercompany.package.download.csv' => ['Scarica CSV', 'Download CSV', 'CSV herunterladen', 'Télécharger CSV'],
            'intercompany.package.download.xlsx' => ['Scarica Excel', 'Download Excel', 'Excel herunterladen', 'Télécharger Excel'],
            'intercompany.package.sections' => ['Contenuto del fascicolo', 'Package contents', 'Inhalt des Berichtspakets', 'Contenu du dossier'],
            'intercompany.package.section.balance_sheet' => ['Stato patrimoniale consolidato', 'Consolidated balance sheet', 'Konsolidierte Bilanz', 'Bilan consolidé'],
            'intercompany.package.section.income_statement' => ['Conto economico consolidato', 'Consolidated income statement', 'Konsolidierte Erfolgsrechnung', 'Compte de résultat consolidé'],
            'intercompany.package.section.cash_flow' => ['Rendiconto finanziario consolidato', 'Consolidated cash flow statement', 'Konsolidierte Geldflussrechnung', 'Tableau de flux de trésorerie consolidé'],
            'intercompany.package.section.presentation' => ['Criteri di presentazione', 'Presentation criteria', 'Darstellungsgrundsätze', 'Critères de présentation'],
            'intercompany.package.section.shareholdings' => ['Prospetto delle partecipazioni e area di consolidamento', 'Schedule of shareholdings and consolidation scope', 'Beteiligungsübersicht und Konsolidierungskreis', 'Tableau des participations et périmètre'],
            'intercompany.package.section.nci_schedule' => ['Prospetto delle quote di terzi', 'Schedule of non-controlling interests', 'Übersicht der Minderheitsanteile', 'Tableau des intérêts minoritaires'],
            'intercompany.package.section.reciprocal_reconciliation' => ['Prospetto di quadratura infragruppo', 'Intercompany reconciliation schedule', 'Konzernabstimmungsübersicht', 'Tableau de rapprochement intragroupe'],
            'intercompany.package.section.elimination_ledger' => ['Prospetto delle scritture di elisione', 'Schedule of elimination entries', 'Übersicht der Eliminierungsbuchungen', 'Tableau des écritures d’élimination'],
            'intercompany.package.section.translation' => ['Conversione e riserva di conversione', 'Translation and translation reserve', 'Umrechnung und Umrechnungsreserve', 'Conversion et réserve de conversion'],
            'intercompany.standard.fer30' => ['Swiss GAAP FER 30', 'Swiss GAAP FER 30', 'Swiss GAAP FER 30', 'Swiss GAAP FER 30'],
            'intercompany.standard.ifrs10' => ['IFRS 10', 'IFRS 10', 'IFRS 10', 'IFRS 10'],
            'intercompany.standard.oic17' => ['OIC 17', 'OIC 17', 'OIC 17', 'OIC 17'],
            'intercompany.standard.fer30.description' => ['Conto consolidato secondo Swiss GAAP FER 30 (Svizzera)', 'Consolidated financial statements under Swiss GAAP FER 30 (Switzerland)', 'Konzernrechnung nach Swiss GAAP FER 30 (Schweiz)', 'Comptes consolidés selon Swiss GAAP FER 30 (Suisse)'],
            'intercompany.standard.ifrs10.description' => ['Bilancio consolidato secondo IFRS 10 (internazionale)', 'Consolidated financial statements under IFRS 10 (international)', 'Konzernabschluss nach IFRS 10 (international)', 'États financiers consolidés selon IFRS 10 (international)'],
            'intercompany.standard.oic17.description' => ['Bilancio consolidato secondo OIC 17 (Italia)', 'Consolidated financial statements under OIC 17 (Italy)', 'Konzernabschluss nach OIC 17 (Italien)', 'Comptes consolidés selon OIC 17 (Italie)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enums(): array
    {
        return [
            'intercompany.enum.mirrorMode.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'intercompany.enum.mirrorMode.confirmed' => ['Confermata', 'Confirmed', 'Bestätigt', 'Confirmée'],
            'intercompany.enum.linkStatus.pending' => ['In attesa', 'Pending', 'Ausstehend', 'En attente'],
            'intercompany.enum.linkStatus.drafted' => ['In bozza', 'Drafted', 'Im Entwurf', 'En brouillon'],
            'intercompany.enum.linkStatus.confirmed' => ['Confermata', 'Confirmed', 'Bestätigt', 'Confirmée'],
            'intercompany.enum.linkStatus.reversed' => ['Stornata', 'Reversed', 'Storniert', 'Contre-passée'],
            'intercompany.enum.linkStatus.failed' => ['Fallita', 'Failed', 'Fehlgeschlagen', 'En échec'],
            'intercompany.enum.linkStatus.skipped' => ['Ignorata', 'Skipped', 'Übersprungen', 'Ignorée'],
            'intercompany.enum.documentType.invoice' => ['Fattura', 'Invoice', 'Rechnung', 'Facture'],
            'intercompany.enum.documentType.debit_note' => ['Nota di addebito', 'Debit note', 'Belastungsanzeige', 'Note de débit'],
            'intercompany.enum.documentType.credit_note' => ['Nota di credito', 'Credit note', 'Gutschrift', 'Note de crédit'],
            'intercompany.enum.paymentStatus.unpaid' => ['Non pagata', 'Unpaid', 'Unbezahlt', 'Impayée'],
            'intercompany.enum.paymentStatus.partial' => ['Pagata in parte', 'Partially paid', 'Teilweise bezahlt', 'Partiellement payée'],
            'intercompany.enum.paymentStatus.paid' => ['Pagata', 'Paid', 'Bezahlt', 'Payée'],
            'intercompany.enum.nettingStatus.draft' => ['Proposta', 'Proposed', 'Vorschlag', 'Proposée'],
            'intercompany.enum.nettingStatus.confirmed' => ['Applicata', 'Applied', 'Angewendet', 'Appliquée'],
            'intercompany.enum.nettingStatus.cancelled' => ['Annullata', 'Cancelled', 'Abgebrochen', 'Annulée'],
            'intercompany.enum.matchStatus.unmatched' => ['Da abbinare', 'Unmatched', 'Offen', 'Non rapprochée'],
            'intercompany.enum.matchStatus.matched' => ['Abbinata', 'Matched', 'Abgeglichen', 'Rapprochée'],
            'intercompany.enum.matchStatus.disputed' => ['Contestata', 'Disputed', 'Beanstandet', 'Contestée'],
            'intercompany.enum.matchStatus.written_off' => ['Stralciata', 'Written off', 'Ausgebucht', 'Passée en perte'],
            'intercompany.enum.matchStatus.adjusted' => ['Rettificata', 'Adjusted', 'Angepasst', 'Ajustée'],
            'intercompany.enum.anomalyType.none' => ['Nessuna anomalia', 'No anomaly', 'Keine Anomalie', 'Aucune anomalie'],
            'intercompany.enum.anomalyType.timing' => ['Differenza temporale', 'Timing difference', 'Zeitliche Differenz', 'Écart temporel'],
            'intercompany.enum.anomalyType.fx' => ['Differenza di cambio', 'Exchange difference', 'Währungsdifferenz', 'Écart de change'],
            'intercompany.enum.anomalyType.amount' => ['Differenza di importo', 'Amount difference', 'Betragsdifferenz', 'Écart de montant'],
            'intercompany.enum.anomalyType.tax' => ['Differenza di aliquota o imposta', 'Tax or rate difference', 'Steuer- oder Satzdifferenz', 'Écart de taxe ou de taux'],
            'intercompany.enum.anomalyType.missing_counterpart' => ['Controparte mancante', 'Missing counterpart', 'Fehlende Gegenbuchung', 'Contrepartie manquante'],
            'intercompany.enum.rateType.closing' => ['Cambio di chiusura', 'Closing rate', 'Stichtagskurs', 'Cours de clôture'],
            'intercompany.enum.rateType.average' => ['Cambio medio', 'Average rate', 'Durchschnittskurs', 'Cours moyen'],
            'intercompany.enum.rateType.historical' => ['Cambio storico', 'Historical rate', 'Historischer Kurs', 'Cours historique'],
            'intercompany.enum.runStatus.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'intercompany.enum.runStatus.calculating' => ['In calcolo', 'Calculating', 'In Berechnung', 'En calcul'],
            'intercompany.enum.runStatus.review' => ['In revisione', 'In review', 'In Prüfung', 'En revue'],
            'intercompany.enum.runStatus.locked' => ['Bloccato', 'Locked', 'Gesperrt', 'Verrouillé'],
            'intercompany.enum.runStatus.posted' => ['Pubblicato', 'Posted', 'Veröffentlicht', 'Publié'],
            'intercompany.enum.standard.fer30' => ['Swiss GAAP FER 30', 'Swiss GAAP FER 30', 'Swiss GAAP FER 30', 'Swiss GAAP FER 30'],
            'intercompany.enum.standard.ifrs10' => ['IFRS 10', 'IFRS 10', 'IFRS 10', 'IFRS 10'],
            'intercompany.enum.standard.oic17' => ['OIC 17', 'OIC 17', 'OIC 17', 'OIC 17'],
            'intercompany.enum.partyRole.customer' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'intercompany.enum.partyRole.supplier' => ['Fornitore', 'Supplier', 'Lieferant', 'Fournisseur'],
            'intercompany.enum.syncEvent.mirror_created' => ['Scrittura a specchio creata', 'Mirror posting created', 'Spiegelbuchung erstellt', 'Écriture miroir créée'],
            'intercompany.enum.syncEvent.mirror_confirmed' => ['Scrittura a specchio confermata', 'Mirror posting confirmed', 'Spiegelbuchung bestätigt', 'Écriture miroir confirmée'],
            'intercompany.enum.syncEvent.mirror_reversed' => ['Scrittura a specchio stornata', 'Mirror posting reversed', 'Spiegelbuchung storniert', 'Écriture miroir contre-passée'],
            'intercompany.enum.syncEvent.payment_synced' => ['Pagamento sincronizzato', 'Payment synchronised', 'Zahlung synchronisiert', 'Paiement synchronisé'],
            'intercompany.enum.syncEvent.payment_variance' => ['Scostamento di pagamento', 'Payment variance', 'Zahlungsabweichung', 'Écart de paiement'],
            'intercompany.enum.syncEvent.netting_applied' => ['Compensazione applicata', 'Netting applied', 'Verrechnung angewendet', 'Compensation appliquée'],
            'intercompany.enum.syncEvent.error' => ['Errore', 'Error', 'Fehler', 'Erreur'],
            'intercompany.enum.eliminationType.intercompany_balance' => ['Elisione crediti e debiti infragruppo', 'Intercompany receivables and payables', 'Konzerninterne Forderungen und Verbindlichkeiten', 'Créances et dettes intragroupe'],
            'intercompany.enum.eliminationType.intercompany_revenue_cost' => ['Elisione ricavi e costi infragruppo', 'Intercompany revenues and costs', 'Konzerninterne Erträge und Aufwände', 'Produits et charges intragroupe'],
            'intercompany.enum.eliminationType.investment_equity' => ['Elisione partecipazioni e patrimonio netto', 'Investment against equity', 'Beteiligung gegen Eigenkapital', 'Participations contre capitaux propres'],
            'intercompany.enum.eliminationType.equity_method' => ['Valutazione a patrimonio netto', 'Equity method', 'Equity-Methode', 'Mise en équivalence'],
            'intercompany.enum.eliminationType.translation_difference' => ['Differenza di conversione', 'Translation difference', 'Umrechnungsdifferenz', 'Écart de conversion'],
            'intercompany.enum.eliminationType.goodwill' => ['Avviamento', 'Goodwill', 'Goodwill', 'Goodwill'],
            'intercompany.enum.eliminationType.goodwill_amortisation' => ['Ammortamento dell’avviamento', 'Goodwill amortisation', 'Goodwill-Abschreibung', 'Amortissement du goodwill'],
            'intercompany.enum.eliminationType.unrealised_profit' => ['Utili interni non realizzati su giacenze', 'Unrealised intercompany profit on stock', 'Nicht realisierte Zwischengewinne auf Vorräten', 'Bénéfices internes non réalisés sur stocks'],
            'intercompany.enum.eliminationType.dividend' => ['Dividendi infragruppo', 'Intercompany dividends', 'Konzerninterne Dividenden', 'Dividendes intragroupe'],
            'intercompany.enum.eliminationType.fair_value_release' => ['Rilascio del fair value', 'Fair value release', 'Auflösung des Fair Value', 'Reprise de la juste valeur'],
            'intercompany.enum.eliminationType.provision_release' => ['Rilascio di accantonamenti', 'Provision release', 'Auflösung von Rückstellungen', 'Reprise de provisions'],
            'intercompany.enum.eliminationType.manual' => ['Rettifica manuale', 'Manual adjustment', 'Manuelle Anpassung', 'Ajustement manuel'],
            'intercompany.enum.consolidationMethod.full' => ['Integrale', 'Full consolidation', 'Vollkonsolidierung', 'Intégration globale'],
            'intercompany.enum.consolidationMethod.proportional' => ['Proporzionale', 'Proportional', 'Quotenkonsolidierung', 'Intégration proportionnelle'],
            'intercompany.enum.consolidationMethod.equity' => ['Patrimonio netto', 'Equity method', 'Equity-Methode', 'Mise en équivalence'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'intercompany.error.invalid' => ['L’operazione non può essere eseguita così com’è.', 'The operation cannot be done as requested.', 'Der Vorgang kann so nicht ausgeführt werden.', 'L’opération ne peut pas être effectuée telle quelle.'],
            'intercompany.error.conflict' => ['La richiesta è in conflitto con lo stato attuale.', 'The request conflicts with the current state.', 'Die Anfrage steht im Konflikt mit dem aktuellen Zustand.', 'La demande est en conflit avec l’état actuel.'],
            'intercompany.error.entity_not_in_group' => ['La società non appartiene al gruppo di consolidamento.', 'The entity does not belong to the consolidation group.', 'Die Gesellschaft gehört nicht zum Konsolidierungskreis.', 'La société n’appartient pas au groupe de consolidation.'],
            'intercompany.error.party_link_missing' => ['Manca il collegamento tra la società emittente e la sua anagrafica fornitore: aggiungilo nella struttura del gruppo.', 'The link between the issuing entity and its supplier contact is missing: add it in the group structure.', 'Die Verknüpfung zwischen der ausstellenden Gesellschaft und ihrem Lieferantenkontakt fehlt: In der Konzernstruktur ergänzen.', 'Le lien entre la société émettrice et sa fiche fournisseur est manquant : ajoutez-le dans la structure du groupe.'],
            'intercompany.error.party_link_exists' => ['Il collegamento esiste già.', 'The link already exists.', 'Die Verknüpfung besteht bereits.', 'Le lien existe déjà.'],
            'intercompany.error.source_missing' => ['Il documento di origine non esiste più.', 'The source document no longer exists.', 'Der Ursprungsbeleg existiert nicht mehr.', 'Le document d’origine n’existe plus.'],
            'intercompany.error.mirror_failed' => ['La scrittura a specchio non è stata generata.', 'The mirror posting could not be generated.', 'Die Spiegelbuchung konnte nicht erzeugt werden.', 'L’écriture miroir n’a pas pu être générée.'],
            'intercompany.error.mirror_not_draft' => ['Solo una scrittura in bozza può essere confermata.', 'Only a draft posting can be confirmed.', 'Nur eine Buchung im Entwurf kann bestätigt werden.', 'Seule une écriture en brouillon peut être confirmée.'],
            'intercompany.error.mirror_not_available' => ['Il documento a specchio non esiste o è stato stornato.', 'The mirror document does not exist or was reversed.', 'Der Spiegelbeleg existiert nicht oder wurde storniert.', 'Le document miroir n’existe pas ou a été contre-passé.'],
            'intercompany.error.payment_variance' => ['Il pagamento differisce dall’importo del documento.', 'The payment differs from the document amount.', 'Die Zahlung weicht vom Belegbetrag ab.', 'Le paiement diffère du montant du document.'],
            'intercompany.error.stale_version' => ['I dati sono stati modificati da un altro utente: ricarica e riprova.', 'The data was changed by another user: reload and try again.', 'Die Daten wurden von einem anderen Benutzer geändert: Bitte neu laden und erneut versuchen.', 'Les données ont été modifiées par un autre utilisateur : rechargez et réessayez.'],
            'intercompany.error.netting_disabled' => ['La compensazione non è abilitata per questo gruppo.', 'Netting is not enabled for this group.', 'Die Verrechnung ist für diesen Konzern nicht aktiviert.', 'La compensation n’est pas activée pour ce groupe.'],
            'intercompany.error.netting_not_draft' => ['Solo una compensazione proposta può essere confermata o annullata.', 'Only a proposed netting can be confirmed or cancelled.', 'Nur ein Verrechnungsvorschlag kann bestätigt oder abgebrochen werden.', 'Seule une compensation proposée peut être confirmée ou annulée.'],
            'intercompany.error.same_entity' => ['La compensazione richiede due società diverse.', 'Netting needs two different entities.', 'Die Verrechnung erfordert zwei verschiedene Gesellschaften.', 'La compensation requiert deux sociétés différentes.'],
            'intercompany.error.rate_missing' => ['Manca un cambio per la data richiesta: pubblicalo o fissalo prima di continuare.', 'An exchange rate is missing for the requested date: publish or fix it before continuing.', 'Für das gewünschte Datum fehlt ein Wechselkurs: Vor dem Fortfahren veröffentlichen oder festlegen.', 'Un taux de change manque pour la date demandée : publiez-le ou fixez-le avant de continuer.'],
            'intercompany.error.rate_invalid' => ['Il cambio deve essere maggiore di zero.', 'The rate must be greater than zero.', 'Der Kurs muss grösser als null sein.', 'Le taux doit être supérieur à zéro.'],
            'intercompany.error.rate_group_currency' => ['La valuta di gruppo non richiede un cambio.', 'The group currency needs no rate.', 'Die Konzernwährung benötigt keinen Kurs.', 'La devise du groupe n’a pas besoin de taux.'],
            'intercompany.error.counterpart_missing' => ['Il documento della controparte non esiste.', 'The counterpart document does not exist.', 'Der Beleg der Gegenpartei existiert nicht.', 'Le document de la contrepartie n’existe pas.'],
            'intercompany.error.counterpart_taken' => ['Il documento della controparte è già abbinato.', 'The counterpart document is already matched.', 'Der Beleg der Gegenpartei ist bereits abgeglichen.', 'Le document de la contrepartie est déjà rapproché.'],
            'intercompany.error.item_closed' => ['La partita è già chiusa.', 'The item is already closed.', 'Der Posten ist bereits abgeschlossen.', 'La partie est déjà clôturée.'],
            'intercompany.error.period_invalid' => ['Il periodo fiscale deve appartenere alla capogruppo.', 'The fiscal period must belong to the parent entity.', 'Die Geschäftsperiode muss zur Muttergesellschaft gehören.', 'La période fiscale doit appartenir à la société mère.'],
            'intercompany.error.run_locked' => ['Il consolidamento è bloccato: riaprilo con un motivo per modificarlo.', 'The consolidation is locked: reopen it with a reason to change it.', 'Die Konsolidierung ist gesperrt: Zum Ändern mit Begründung wieder öffnen.', 'La consolidation est verrouillée : rouvrez-la avec un motif pour la modifier.'],
            'intercompany.error.run_not_locked' => ['Solo un consolidamento bloccato o pubblicato può essere riaperto.', 'Only a locked or posted consolidation can be reopened.', 'Nur eine gesperrte oder veröffentlichte Konsolidierung kann wieder geöffnet werden.', 'Seule une consolidation verrouillée ou publiée peut être rouverte.'],
            'intercompany.error.illegal_transition' => ['Passaggio di stato non consentito.', 'This status change is not allowed.', 'Dieser Statuswechsel ist nicht zulässig.', 'Ce changement de statut n’est pas autorisé.'],
            'intercompany.error.inputs_changed' => ['I libri, i cambi o la configurazione sono cambiati: crea un nuovo consolidamento.', 'The ledgers, rates or configuration changed: create a new consolidation.', 'Die Bücher, Kurse oder die Konfiguration haben sich geändert: Neue Konsolidierung erstellen.', 'Les livres, les taux ou la configuration ont changé : créez une nouvelle consolidation.'],
            'intercompany.error.adjustment_unbalanced' => ['La rettifica non quadra: dare e avere devono coincidere.', 'The adjustment does not balance: debits and credits must be equal.', 'Die Anpassung ist nicht ausgeglichen: Soll und Haben müssen übereinstimmen.', 'L’ajustement n’est pas équilibré : le débit et le crédit doivent être égaux.'],
            'intercompany.error.adjustment_line_invalid' => ['Ogni riga ha un importo o a dare o ad avere.', 'Every line has either a debit or a credit amount.', 'Jede Zeile hat entweder einen Soll- oder einen Haben-Betrag.', 'Chaque ligne a soit un montant au débit, soit un montant au crédit.'],
            'intercompany.error.adjustment_void' => ['La rettifica è già annullata.', 'The adjustment is already void.', 'Die Anpassung ist bereits storniert.', 'L’ajustement est déjà annulé.'],
        ];
    }
}
