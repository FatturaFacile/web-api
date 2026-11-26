<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the multi-currency engine (Mod 003): the exchange rates page (table, filters, trend, manual rate dialog,
 * audit export), the ECB / SNB / ESTV synchronisation runs, the currency settings, the exposure widget, the period-end FX
 * revaluation wizard and the exchange rate field of the document forms.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, ECB/BCE, SNB/BNS, ESTV/AFC, MWST/IVA/TVA) stay untranslated.
 */
final class CurrencyFxFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::currencyEnumerations(),
            self::currencyLabels(),
            self::currencyMessages(),
            self::exposure(),
            self::revaluation(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.FINANCE_CURRENCIES' => ['Valute e cambi', 'Currencies and exchange rates', 'Währungen und Wechselkurse', 'Devises et taux de change'],
            'NAV.FX_REVALUATION' => ['Rivalutazione cambi', 'FX revaluation', 'Fremdwährungsbewertung', 'Réévaluation des devises'],
            'PAGE_TITLE.FINANCE_CURRENCIES' => ['Valute, tassi di cambio e sincronizzazione BCE / BNS', 'Currencies, exchange rates and ECB / SNB synchronisation', 'Währungen, Wechselkurse und Synchronisation EZB / SNB', 'Devises, taux de change et synchronisation BCE / BNS'],
            'PAGE_TITLE.FX_REVALUATION' => ['Rivalutazione di fine periodo delle posizioni in valuta', 'Period-end revaluation of foreign currency positions', 'Bewertung der Fremdwährungspositionen zum Periodenende', 'Réévaluation de fin de période des positions en devises'],
            'ERRORS.EXCHANGE_RATE_MISSING' => ['Nessun tasso di cambio per {currency} entro 5 giorni dal {date}. Inserisci un tasso manuale.', 'No exchange rate for {currency} within 5 days of {date}. Enter a manual rate.', 'Kein Wechselkurs für {currency} innerhalb von 5 Tagen vor dem {date}. Geben Sie einen manuellen Kurs ein.', 'Aucun taux de change pour {currency} dans les 5 jours précédant le {date}. Saisissez un taux manuel.'],
        ];
    }

    /**
     * Enumerations: rate types, sources, providers, statuses, treatments, VAT methods.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function currencyEnumerations(): array
    {
        return [
            'FINANCE.CURRENCIES.RATE_TYPE.mid' => ['Medio', 'Mid', 'Mittelkurs', 'Moyen'],
            'FINANCE.CURRENCIES.RATE_TYPE.buy' => ['Acquisto', 'Buy', 'Ankauf', 'Achat'],
            'FINANCE.CURRENCIES.RATE_TYPE.sell' => ['Vendita', 'Sell', 'Verkauf', 'Vente'],
            'FINANCE.CURRENCIES.RATE_TYPE.contract' => ['Contrattuale', 'Contract', 'Vertragskurs', 'Contractuel'],
            'FINANCE.CURRENCIES.RATE_TYPE.closing' => ['Chiusura', 'Closing', 'Stichtagskurs', 'Clôture'],
            'FINANCE.CURRENCIES.RATE_TYPE.vat_estv_monthly' => ['IVA mensile ESTV', 'VAT monthly ESTV', 'MWST-Monatskurs ESTV', 'TVA mensuel AFC'],
            'FINANCE.CURRENCIES.SOURCE.ecb' => ['BCE', 'ECB', 'EZB', 'BCE'],
            'FINANCE.CURRENCIES.SOURCE.snb' => ['BNS', 'SNB', 'SNB', 'BNS'],
            'FINANCE.CURRENCIES.SOURCE.estv' => ['ESTV', 'ESTV', 'ESTV', 'AFC'],
            'FINANCE.CURRENCIES.SOURCE.manual' => ['Manuale', 'Manual', 'Manuell', 'Manuel'],
            'FINANCE.CURRENCIES.PROVIDER.ecb' => ['BCE (Banca centrale europea)', 'ECB (European Central Bank)', 'EZB (Europäische Zentralbank)', 'BCE (Banque centrale européenne)'],
            'FINANCE.CURRENCIES.PROVIDER.snb' => ['BNS (Banca nazionale svizzera)', 'SNB (Swiss National Bank)', 'SNB (Schweizerische Nationalbank)', 'BNS (Banque nationale suisse)'],
            'FINANCE.CURRENCIES.PROVIDER.estv' => ['ESTV (tassi IVA mensili)', 'ESTV (monthly VAT rates)', 'ESTV (MWST-Monatskurse)', 'AFC (taux TVA mensuels)'],
            'FINANCE.CURRENCIES.SYNC_STATUS.queued' => ['In coda', 'Queued', 'In Warteschlange', 'En file d\'attente'],
            'FINANCE.CURRENCIES.SYNC_STATUS.running' => ['In esecuzione', 'Running', 'Läuft', 'En cours'],
            'FINANCE.CURRENCIES.SYNC_STATUS.succeeded' => ['Riuscita', 'Succeeded', 'Erfolgreich', 'Réussie'],
            'FINANCE.CURRENCIES.SYNC_STATUS.failed' => ['Fallita', 'Failed', 'Fehlgeschlagen', 'Échouée'],
            'FINANCE.CURRENCIES.TRIGGER.scheduled' => ['Pianificata', 'Scheduled', 'Geplant', 'Planifiée'],
            'FINANCE.CURRENCIES.TRIGGER.manual' => ['Manuale', 'Manual', 'Manuell', 'Manuelle'],
            'FINANCE.CURRENCIES.TREATMENT.recognise' => ['Rileva a conto economico', 'Recognise in profit or loss', 'Erfolgswirksam erfassen', 'Comptabiliser en résultat'],
            'FINANCE.CURRENCIES.TREATMENT.provision' => ['Accantona a fondo rischi', 'Set aside as provision', 'Als Rückstellung bilden', 'Constituer une provision'],
            'FINANCE.CURRENCIES.TREATMENT.suppress' => ['Non rilevare', 'Do not recognise', 'Nicht erfassen', 'Ne pas comptabiliser'],
            'FINANCE.CURRENCIES.VAT_METHOD.estv_monthly' => ['Tasso mensile ESTV', 'ESTV monthly rate', 'ESTV-Monatskurs', 'Taux mensuel AFC'],
            'FINANCE.CURRENCIES.VAT_METHOD.daily' => ['Tasso giornaliero', 'Daily rate', 'Tageskurs', 'Taux journalier'],
            'FINANCE.CURRENCIES.VAT_METHOD.ecb' => ['Tasso BCE', 'ECB rate', 'EZB-Kurs', 'Taux BCE'],
        ];
    }

    /**
     * Fields, columns, tabs, actions, badges, hints and the trend of the rates page and the document field.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function currencyLabels(): array
    {
        return [
            'FINANCE.CURRENCIES.TAB.RATES' => ['Tassi', 'Rates', 'Kurse', 'Taux'],
            'FINANCE.CURRENCIES.TAB.SYNC' => ['Sincronizzazione', 'Synchronisation', 'Synchronisation', 'Synchronisation'],
            'FINANCE.CURRENCIES.TAB.EXPOSURE' => ['Esposizione', 'Exposure', 'Exposition', 'Exposition'],
            'FINANCE.CURRENCIES.TAB.SETTINGS' => ['Impostazioni', 'Settings', 'Einstellungen', 'Paramètres'],
            'FINANCE.CURRENCIES.FIELD.BASE' => ['Valuta base', 'Base currency', 'Basiswährung', 'Devise de base'],
            'FINANCE.CURRENCIES.FIELD.QUOTE' => ['Valuta di quotazione', 'Quote currency', 'Gegenwährung', 'Devise de cotation'],
            'FINANCE.CURRENCIES.FIELD.RATE' => ['Tasso', 'Rate', 'Kurs', 'Taux'],
            'FINANCE.CURRENCIES.FIELD.VALID_ON' => ['Valido dal', 'Valid on', 'Gültig am', 'Valable le'],
            'FINANCE.CURRENCIES.FIELD.RATE_TYPE' => ['Tipo di tasso', 'Rate type', 'Kursart', 'Type de taux'],
            'FINANCE.CURRENCIES.FIELD.REASON' => ['Motivo', 'Reason', 'Begründung', 'Motif'],
            'FINANCE.CURRENCIES.FIELD.CONTRACT_REFERENCE' => ['Riferimento contratto', 'Contract reference', 'Vertragsreferenz', 'Référence du contrat'],
            'FINANCE.CURRENCIES.FIELD.DOCUMENT_CURRENCY' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'FINANCE.CURRENCIES.FIELD.EXCHANGE_RATE' => ['Tasso di cambio', 'Exchange rate', 'Wechselkurs', 'Taux de change'],
            'FINANCE.CURRENCIES.FIELD.FROM' => ['Dal', 'From', 'Von', 'Du'],
            'FINANCE.CURRENCIES.FIELD.TO' => ['Al', 'To', 'Bis', 'Au'],
            'FINANCE.CURRENCIES.MANUAL_RATE.TITLE' => ['Inserisci un tasso manuale', 'Enter a manual rate', 'Manuellen Kurs erfassen', 'Saisir un taux manuel'],
            'FINANCE.CURRENCIES.HINT.RATE_PRECISION' => ['Positivo, al massimo 6 decimali (es. 0.941200).', 'Positive, at most 6 decimals (for example 0.941200).', 'Positiv, höchstens 6 Dezimalstellen (z. B. 0.941200).', 'Positif, 6 décimales au maximum (par ex. 0.941200).'],
            'FINANCE.CURRENCIES.HINT.FALLBACK_DATE' => ['Tasso del {date}: nessun tasso alla data del documento.', 'Rate of {date}: there is no rate on the document date.', 'Kurs vom {date}: für das Belegdatum liegt kein Kurs vor.', 'Taux du {date} : il n\'y a pas de taux à la date du document.'],
            'FINANCE.CURRENCIES.HINT.RESOLVING' => ['Ricerca del tasso in corso…', 'Looking up the rate…', 'Kurs wird gesucht…', 'Recherche du taux…'],
            'FINANCE.CURRENCIES.HINT.ACTIVE_CURRENCIES' => ['La valuta funzionale resta sempre attiva. Non si può disattivare una valuta con partite aperte.', 'The functional currency always stays active. A currency with open items cannot be deactivated.', 'Die Funktionalwährung bleibt immer aktiv. Eine Währung mit offenen Posten kann nicht deaktiviert werden.', 'La devise fonctionnelle reste toujours active. Une devise avec des positions ouvertes ne peut pas être désactivée.'],
            'FINANCE.CURRENCIES.HINT.ALERT_THRESHOLD' => ['Avvisa quando un tasso varia oltre questa percentuale rispetto al precedente (0-100, max 2 decimali).', 'Alert when a rate moves by more than this percentage from the previous one (0-100, at most 2 decimals).', 'Warnung, wenn ein Kurs um mehr als diesen Prozentsatz vom vorherigen abweicht (0-100, höchstens 2 Dezimalstellen).', 'Alerter quand un taux varie de plus de ce pourcentage par rapport au précédent (0-100, 2 décimales au maximum).'],
            'FINANCE.CURRENCIES.HINT.UNREALIZED_TREATMENT' => ['Come trattare gli utili non realizzati nella rivalutazione di fine periodo.', 'How unrealised gains are treated in the period-end revaluation.', 'Wie nicht realisierte Gewinne in der Periodenendbewertung behandelt werden.', 'Traitement des gains latents dans la réévaluation de fin de période.'],
            'FINANCE.CURRENCIES.BADGE.CONTRACT' => ['Tasso contrattuale', 'Contract rate', 'Vertragskurs', 'Taux contractuel'],
            'FINANCE.CURRENCIES.BADGE.MANUAL' => ['Inserito a mano', 'Entered by hand', 'Manuell erfasst', 'Saisi à la main'],
            'FINANCE.CURRENCIES.BADGE.DERIVED' => ['Derivato (incrocio)', 'Derived (cross rate)', 'Abgeleitet (Kreuzkurs)', 'Dérivé (taux croisé)'],
            'FINANCE.CURRENCIES.BADGE.SUPERSEDED' => ['Sostituito', 'Superseded', 'Ersetzt', 'Remplacé'],
            'FINANCE.CURRENCIES.BADGE.OFFICIAL' => ['Ufficiale', 'Official', 'Offiziell', 'Officiel'],
            'FINANCE.CURRENCIES.TREND.TITLE' => ['Andamento {pair}', 'Trend {pair}', 'Verlauf {pair}', 'Évolution {pair}'],
            'FINANCE.CURRENCIES.TREND.NOT_ENOUGH_DATA' => ['Filtra una coppia di valute con almeno due tassi per vedere l\'andamento.', 'Filter a currency pair with at least two rates to see the trend.', 'Filtern Sie ein Währungspaar mit mindestens zwei Kursen, um den Verlauf zu sehen.', 'Filtrez une paire de devises avec au moins deux taux pour voir l\'évolution.'],
            'FINANCE.CURRENCIES.TREND.ARIA' => ['Andamento del tasso {pair} dal {from} al {to}', 'Trend of the {pair} rate from {from} to {to}', 'Verlauf des Kurses {pair} vom {from} bis {to}', 'Évolution du taux {pair} du {from} au {to}'],
            'FINANCE.CURRENCIES.COLUMN.PAIR' => ['Coppia', 'Pair', 'Paar', 'Paire'],
            'FINANCE.CURRENCIES.COLUMN.RATE' => ['Tasso', 'Rate', 'Kurs', 'Taux'],
            'FINANCE.CURRENCIES.COLUMN.RATE_TYPE' => ['Tipo', 'Type', 'Art', 'Type'],
            'FINANCE.CURRENCIES.COLUMN.SOURCE' => ['Fonte', 'Source', 'Quelle', 'Source'],
            'FINANCE.CURRENCIES.COLUMN.VALID_ON' => ['Data', 'Date', 'Datum', 'Date'],
            'FINANCE.CURRENCIES.COLUMN.NOTES' => ['Note', 'Notes', 'Hinweise', 'Remarques'],
            'FINANCE.CURRENCIES.COLUMN.CURRENCY' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'FINANCE.CURRENCIES.COLUMN.PROVIDER' => ['Fonte dati', 'Provider', 'Anbieter', 'Fournisseur'],
            'FINANCE.CURRENCIES.COLUMN.STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'FINANCE.CURRENCIES.COLUMN.TRIGGER' => ['Avvio', 'Trigger', 'Auslöser', 'Déclenchement'],
            'FINANCE.CURRENCIES.COLUMN.STARTED_AT' => ['Iniziata', 'Started', 'Gestartet', 'Démarrée'],
            'FINANCE.CURRENCIES.COLUMN.FINISHED_AT' => ['Terminata', 'Finished', 'Beendet', 'Terminée'],
            'FINANCE.CURRENCIES.COLUMN.RATES_COUNT' => ['Tassi', 'Rates', 'Kurse', 'Taux'],
            'FINANCE.CURRENCIES.COLUMN.DERIVED_COUNT' => ['Derivati', 'Derived', 'Abgeleitet', 'Dérivés'],
            'FINANCE.CURRENCIES.COLUMN.ERROR' => ['Errore', 'Error', 'Fehler', 'Erreur'],
            'FINANCE.CURRENCIES.EMPTY.RATES' => ['Nessun tasso per i filtri scelti.', 'No rates for the chosen filters.', 'Keine Kurse für die gewählten Filter.', 'Aucun taux pour les filtres choisis.'],
            'FINANCE.CURRENCIES.EMPTY.SYNC_RUNS' => ['Nessuna sincronizzazione eseguita.', 'No synchronisation has run yet.', 'Es wurde noch keine Synchronisation ausgeführt.', 'Aucune synchronisation n\'a encore été exécutée.'],
            'FINANCE.CURRENCIES.SETTINGS.FUNCTIONAL_CURRENCY' => ['Valuta funzionale', 'Functional currency', 'Funktionalwährung', 'Devise fonctionnelle'],
            'FINANCE.CURRENCIES.SETTINGS.ACTIVE_CURRENCIES' => ['Valute attive', 'Active currencies', 'Aktive Währungen', 'Devises actives'],
            'FINANCE.CURRENCIES.SETTINGS.ALERT_THRESHOLD' => ['Soglia di avviso variazione tasso (%)', 'Rate change alert threshold (%)', 'Warnschwelle für Kursänderungen (%)', 'Seuil d\'alerte de variation du taux (%)'],
            'FINANCE.CURRENCIES.SETTINGS.UNREALIZED_TREATMENT' => ['Trattamento degli utili non realizzati', 'Treatment of unrealised gains', 'Behandlung nicht realisierter Gewinne', 'Traitement des gains latents'],
            'FINANCE.CURRENCIES.SETTINGS.VAT_RATE_METHOD' => ['Metodo del tasso per l\'IVA', 'VAT rate method', 'Kursmethode für die MWST', 'Méthode de taux pour la TVA'],
            'FINANCE.CURRENCIES.ACTION.CANCEL' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'FINANCE.CURRENCIES.ACTION.SAVE_RATE' => ['Salva tasso', 'Save rate', 'Kurs speichern', 'Enregistrer le taux'],
            'FINANCE.CURRENCIES.ACTION.ENTER_MANUAL_RATE' => ['Inserisci tasso manuale', 'Enter manual rate', 'Manuellen Kurs erfassen', 'Saisir un taux manuel'],
            'FINANCE.CURRENCIES.ACTION.SYNC_NOW' => ['Aggiorna ora', 'Update now', 'Jetzt aktualisieren', 'Mettre à jour maintenant'],
            'FINANCE.CURRENCIES.ACTION.REFRESH' => ['Ricarica elenco', 'Reload list', 'Liste neu laden', 'Recharger la liste'],
            'FINANCE.CURRENCIES.ACTION.NEW_MANUAL_RATE' => ['Nuovo tasso manuale', 'New manual rate', 'Neuer manueller Kurs', 'Nouveau taux manuel'],
            'FINANCE.CURRENCIES.ACTION.EXPORT_CSV' => ['Esporta CSV (audit)', 'Export CSV (audit)', 'CSV exportieren (Audit)', 'Exporter en CSV (audit)'],
            'FINANCE.CURRENCIES.ACTION.EXPORT_XLSX' => ['Esporta XLSX (audit)', 'Export XLSX (audit)', 'XLSX exportieren (Audit)', 'Exporter en XLSX (audit)'],
            'FINANCE.CURRENCIES.ACTION.OPEN_REVALUATION' => ['Rivalutazione cambi', 'FX revaluation', 'Fremdwährungsbewertung', 'Réévaluation des devises'],
            'FINANCE.CURRENCIES.ACTION.SAVE_SETTINGS' => ['Salva impostazioni', 'Save settings', 'Einstellungen speichern', 'Enregistrer les paramètres'],
        ];
    }

    /**
     * Notifications and refusals of the rates page, the synchronisation and the settings.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function currencyMessages(): array
    {
        return [
            'FINANCE.CURRENCIES.MESSAGE.RATE_SAVED' => ['Tasso salvato.', 'Rate saved.', 'Kurs gespeichert.', 'Taux enregistré.'],
            'FINANCE.CURRENCIES.MESSAGE.SYNC_QUEUED' => ['Aggiornamento dei tassi avviato.', 'Rate update started.', 'Kursaktualisierung gestartet.', 'Mise à jour des taux lancée.'],
            'FINANCE.CURRENCIES.MESSAGE.SYNC_ALREADY_RUNNING' => ['Un aggiornamento dei tassi è già in corso: attendi che termini.', 'A rate update is already running: wait until it finishes.', 'Eine Kursaktualisierung läuft bereits: Warten Sie, bis sie beendet ist.', 'Une mise à jour des taux est déjà en cours : attendez qu\'elle se termine.'],
            'FINANCE.CURRENCIES.MESSAGE.SETTINGS_SAVED' => ['Impostazioni valute salvate.', 'Currency settings saved.', 'Währungseinstellungen gespeichert.', 'Paramètres des devises enregistrés.'],
            'FINANCE.CURRENCIES.MESSAGE.EXPORT_READY' => ['Esportazione scaricata.', 'Export downloaded.', 'Export heruntergeladen.', 'Export téléchargé.'],
            'FINANCE.CURRENCIES.NOTIFICATION.SYNC_FAILED_TITLE' => ['Sincronizzazione dei tassi di cambio non riuscita', 'Exchange-rate synchronisation failed', 'Synchronisation der Wechselkurse fehlgeschlagen', 'Échec de la synchronisation des taux de change'],
            'FINANCE.CURRENCIES.NOTIFICATION.SYNC_FAILED_BODY' => ['La sincronizzazione dei tassi da {provider} è fallita: {error}', 'The synchronisation of the rates from {provider} failed: {error}', 'Die Synchronisation der Kurse von {provider} ist fehlgeschlagen: {error}', 'La synchronisation des taux de {provider} a échoué : {error}'],
            'FINANCE.CURRENCIES.NOTIFICATION.RATE_MOVEMENT_TITLE' => ['Forte variazione del tasso di cambio', 'Large exchange-rate movement', 'Grosse Wechselkursbewegung', 'Forte variation du taux de change'],
            'FINANCE.CURRENCIES.NOTIFICATION.RATE_MOVEMENT_BODY' => ['Il tasso {currency}/{functional_currency} è variato del {change_percent}% il {date}.', 'The {currency}/{functional_currency} rate moved by {change_percent}% on {date}.', 'Der Kurs {currency}/{functional_currency} hat sich am {date} um {change_percent}% verändert.', 'Le taux {currency}/{functional_currency} a varié de {change_percent}% le {date}.'],
            'FINANCE.CURRENCIES.ERROR.REQUIRED' => ['Campo obbligatorio.', 'This field is required.', 'Pflichtfeld.', 'Champ obligatoire.'],
            'FINANCE.CURRENCIES.ERROR.RATE_FORMAT' => ['Inserisci un numero decimale con al massimo 6 decimali.', 'Enter a decimal number with at most 6 decimals.', 'Geben Sie eine Dezimalzahl mit höchstens 6 Dezimalstellen ein.', 'Saisissez un nombre décimal avec 6 décimales au maximum.'],
            'FINANCE.CURRENCIES.ERROR.RATE_POSITIVE' => ['Il tasso deve essere maggiore di zero.', 'The rate must be greater than zero.', 'Der Kurs muss grösser als null sein.', 'Le taux doit être supérieur à zéro.'],
            'FINANCE.CURRENCIES.ERROR.CURRENCY_CODE' => ['Codice di tre lettere maiuscole (es. EUR).', 'Three capital letters (for example EUR).', 'Drei Grossbuchstaben (z. B. EUR).', 'Trois lettres majuscules (par ex. EUR).'],
            'FINANCE.CURRENCIES.ERROR.PERCENT_FORMAT' => ['Percentuale da 0 a 100 con al massimo 2 decimali.', 'A percentage from 0 to 100 with at most 2 decimals.', 'Prozentsatz von 0 bis 100 mit höchstens 2 Dezimalstellen.', 'Pourcentage de 0 à 100 avec 2 décimales au maximum.'],
            'FINANCE.CURRENCIES.ERROR.LOAD_FAILED' => ['Impossibile caricare i dati delle valute.', 'The currency data could not be loaded.', 'Die Währungsdaten konnten nicht geladen werden.', 'Les données des devises n\'ont pas pu être chargées.'],
            'FINANCE.CURRENCIES.ERROR.EXPORT_FAILED' => ['Impossibile esportare i tassi.', 'The rates could not be exported.', 'Die Kurse konnten nicht exportiert werden.', 'Les taux n\'ont pas pu être exportés.'],
            'FINANCE.CURRENCIES.ERROR.SYNC_FAILED' => ['Impossibile avviare l\'aggiornamento dei tassi.', 'The rate update could not be started.', 'Die Kursaktualisierung konnte nicht gestartet werden.', 'La mise à jour des taux n\'a pas pu être lancée.'],
            'FINANCE.CURRENCIES.ERROR.EXPOSURE_FAILED' => ['Impossibile calcolare l\'esposizione in valuta.', 'The foreign currency exposure could not be calculated.', 'Die Fremdwährungsexposition konnte nicht berechnet werden.', 'L\'exposition en devises n\'a pas pu être calculée.'],
            'FINANCE.CURRENCIES.ERROR.SETTINGS_CONFLICT' => ['Una valuta da disattivare ha ancora partite o saldi aperti.', 'A currency to deactivate still has open items or balances.', 'Eine zu deaktivierende Währung hat noch offene Posten oder Salden.', 'Une devise à désactiver a encore des positions ou des soldes ouverts.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function exposure(): array
    {
        return [
            'FINANCE.CURRENCIES.EXPOSURE.RATE_MISSING_WARNING' => ['Per alcune valute manca il tasso di chiusura: il valore corrente non è affidabile.', 'The closing rate is missing for some currencies: the current value is not reliable.', 'Für einige Währungen fehlt der Stichtagskurs: Der aktuelle Wert ist nicht verlässlich.', 'Le taux de clôture manque pour certaines devises : la valeur actuelle n\'est pas fiable.'],
            'FINANCE.CURRENCIES.EXPOSURE.RATE_MISSING' => ['Tasso mancante', 'Rate missing', 'Kurs fehlt', 'Taux manquant'],
            'FINANCE.CURRENCIES.EXPOSURE.HISTORICAL_TOTAL' => ['Valore storico totale', 'Total historical value', 'Historischer Gesamtwert', 'Valeur historique totale'],
            'FINANCE.CURRENCIES.EXPOSURE.CURRENT_TOTAL' => ['Valore corrente totale', 'Total current value', 'Aktueller Gesamtwert', 'Valeur actuelle totale'],
            'FINANCE.CURRENCIES.EXPOSURE.UNREALIZED_TOTAL' => ['Utile / perdita non realizzati', 'Unrealised gain / loss', 'Nicht realisierter Gewinn / Verlust', 'Gain / perte latent(e)'],
            'FINANCE.CURRENCIES.EXPOSURE.RECEIVABLES' => ['Crediti', 'Receivables', 'Forderungen', 'Créances'],
            'FINANCE.CURRENCIES.EXPOSURE.PAYABLES' => ['Debiti', 'Payables', 'Verbindlichkeiten', 'Dettes'],
            'FINANCE.CURRENCIES.EXPOSURE.BANK' => ['Banca', 'Bank', 'Bank', 'Banque'],
            'FINANCE.CURRENCIES.EXPOSURE.CASH' => ['Cassa', 'Cash', 'Kasse', 'Caisse'],
            'FINANCE.CURRENCIES.EXPOSURE.NET' => ['Netto in valuta', 'Net in currency', 'Netto in Währung', 'Net en devise'],
            'FINANCE.CURRENCIES.EXPOSURE.HISTORICAL' => ['Valore storico', 'Historical value', 'Historischer Wert', 'Valeur historique'],
            'FINANCE.CURRENCIES.EXPOSURE.CURRENT' => ['Valore corrente', 'Current value', 'Aktueller Wert', 'Valeur actuelle'],
            'FINANCE.CURRENCIES.EXPOSURE.UNREALIZED' => ['Non realizzato', 'Unrealised', 'Nicht realisiert', 'Latent'],
            'FINANCE.CURRENCIES.EMPTY.EXPOSURE' => ['Nessuna posizione in valuta estera.', 'No foreign currency positions.', 'Keine Fremdwährungspositionen.', 'Aucune position en devises.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function revaluation(): array
    {
        return [
            'FINANCE.FX_REVALUATION.ACTION.BACK_TO_RATES' => ['Torna a valute e cambi', 'Back to currencies and rates', 'Zurück zu Währungen und Kursen', 'Retour aux devises et taux'],
            'FINANCE.FX_REVALUATION.ACTION.SIMULATE' => ['Simula rivalutazione', 'Simulate revaluation', 'Bewertung simulieren', 'Simuler la réévaluation'],
            'FINANCE.FX_REVALUATION.ACTION.BACK' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'FINANCE.FX_REVALUATION.ACTION.CONTINUE' => ['Continua alla registrazione', 'Continue to posting', 'Weiter zur Buchung', 'Continuer vers la comptabilisation'],
            'FINANCE.FX_REVALUATION.ACTION.POST' => ['Registra a libro giornale', 'Post to the journal', 'Im Journal buchen', 'Comptabiliser au journal'],
            'FINANCE.FX_REVALUATION.ACTION.NEW_REVALUATION' => ['Nuova rivalutazione', 'New revaluation', 'Neue Bewertung', 'Nouvelle réévaluation'],
            'FINANCE.FX_REVALUATION.ACTION.REVERSE' => ['Storna', 'Reverse', 'Stornieren', 'Extourner'],
            'FINANCE.FX_REVALUATION.ACTION.OPEN' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'FINANCE.FX_REVALUATION.STEP.PERIOD' => ['Periodo', 'Period', 'Periode', 'Période'],
            'FINANCE.FX_REVALUATION.STEP.PREVIEW' => ['Anteprima righe', 'Line preview', 'Zeilenvorschau', 'Aperçu des lignes'],
            'FINANCE.FX_REVALUATION.STEP.POST' => ['Conferma e registra', 'Confirm and post', 'Bestätigen und buchen', 'Confirmer et comptabiliser'],
            'FINANCE.FX_REVALUATION.FIELD.PERIOD_END' => ['Fine periodo', 'Period end', 'Periodenende', 'Fin de période'],
            'FINANCE.FX_REVALUATION.FIELD.PERIOD_TYPE' => ['Tipo di periodo', 'Period type', 'Periodenart', 'Type de période'],
            'FINANCE.FX_REVALUATION.FIELD.CURRENCIES' => ['Valute (facoltativo)', 'Currencies (optional)', 'Währungen (optional)', 'Devises (facultatif)'],
            'FINANCE.FX_REVALUATION.PERIOD_TYPE.monthly' => ['Mensile', 'Monthly', 'Monatlich', 'Mensuelle'],
            'FINANCE.FX_REVALUATION.PERIOD_TYPE.quarterly' => ['Trimestrale', 'Quarterly', 'Quartalsweise', 'Trimestrielle'],
            'FINANCE.FX_REVALUATION.PERIOD_TYPE.annual' => ['Annuale', 'Annual', 'Jährlich', 'Annuelle'],
            'FINANCE.FX_REVALUATION.HINT.CURRENCIES' => ['Codici di tre lettere (es. EUR, USD). Vuoto = tutte le valute attive.', 'Three-letter codes (for example EUR, USD). Empty = all active currencies.', 'Dreibuchstabige Codes (z. B. EUR, USD). Leer = alle aktiven Währungen.', 'Codes à trois lettres (par ex. EUR, USD). Vide = toutes les devises actives.'],
            'FINANCE.FX_REVALUATION.HINT.POST' => ['Controlla i totali: la registrazione crea la scrittura contabile della rivalutazione e non si ripete se riprovi.', 'Check the totals: posting creates the revaluation journal entry and is not repeated if you retry.', 'Prüfen Sie die Summen: Die Buchung erzeugt den Bewertungsbeleg und wird bei einem erneuten Versuch nicht wiederholt.', 'Vérifiez les totaux : la comptabilisation crée l\'écriture de réévaluation et n\'est pas répétée si vous réessayez.'],
            'FINANCE.FX_REVALUATION.COLUMN.LINE_TYPE' => ['Tipo', 'Type', 'Art', 'Type'],
            'FINANCE.FX_REVALUATION.COLUMN.CURRENCY' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'FINANCE.FX_REVALUATION.COLUMN.REFERENCE' => ['Riferimento', 'Reference', 'Referenz', 'Référence'],
            'FINANCE.FX_REVALUATION.COLUMN.CURRENCY_BALANCE' => ['Saldo in valuta', 'Balance in currency', 'Saldo in Währung', 'Solde en devise'],
            'FINANCE.FX_REVALUATION.COLUMN.BOOK_VALUE' => ['Valore contabile', 'Book value', 'Buchwert', 'Valeur comptable'],
            'FINANCE.FX_REVALUATION.COLUMN.CLOSING_RATE' => ['Tasso di chiusura', 'Closing rate', 'Stichtagskurs', 'Taux de clôture'],
            'FINANCE.FX_REVALUATION.COLUMN.REVALUED_VALUE' => ['Valore rivalutato', 'Revalued value', 'Neubewerteter Wert', 'Valeur réévaluée'],
            'FINANCE.FX_REVALUATION.COLUMN.DIFFERENCE' => ['Differenza', 'Difference', 'Differenz', 'Différence'],
            'FINANCE.FX_REVALUATION.COLUMN.RESULT' => ['Esito', 'Result', 'Ergebnis', 'Résultat'],
            'FINANCE.FX_REVALUATION.COLUMN.TREATMENT' => ['Trattamento', 'Treatment', 'Behandlung', 'Traitement'],
            'FINANCE.FX_REVALUATION.COLUMN.PERIOD_END' => ['Fine periodo', 'Period end', 'Periodenende', 'Fin de période'],
            'FINANCE.FX_REVALUATION.COLUMN.PERIOD_TYPE' => ['Periodo', 'Period', 'Periode', 'Période'],
            'FINANCE.FX_REVALUATION.COLUMN.STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'FINANCE.FX_REVALUATION.LINE_TYPE.receivable' => ['Credito', 'Receivable', 'Forderung', 'Créance'],
            'FINANCE.FX_REVALUATION.LINE_TYPE.payable' => ['Debito', 'Payable', 'Verbindlichkeit', 'Dette'],
            'FINANCE.FX_REVALUATION.LINE_TYPE.bank' => ['Banca', 'Bank', 'Bank', 'Banque'],
            'FINANCE.FX_REVALUATION.LINE_TYPE.cash' => ['Cassa', 'Cash', 'Kasse', 'Caisse'],
            'FINANCE.FX_REVALUATION.RESULT.gain' => ['Utile', 'Gain', 'Gewinn', 'Gain'],
            'FINANCE.FX_REVALUATION.RESULT.loss' => ['Perdita', 'Loss', 'Verlust', 'Perte'],
            'FINANCE.FX_REVALUATION.RESULT.neutral' => ['Neutro', 'Neutral', 'Neutral', 'Neutre'],
            'FINANCE.FX_REVALUATION.STATUS.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'FINANCE.FX_REVALUATION.STATUS.posted' => ['Registrata', 'Posted', 'Gebucht', 'Comptabilisée'],
            'FINANCE.FX_REVALUATION.STATUS.reversed' => ['Stornata', 'Reversed', 'Storniert', 'Extournée'],
            'FINANCE.FX_REVALUATION.TOTAL.PERIOD' => ['Fine periodo', 'Period end', 'Periodenende', 'Fin de période'],
            'FINANCE.FX_REVALUATION.TOTAL.GAIN' => ['Utili', 'Gains', 'Gewinne', 'Gains'],
            'FINANCE.FX_REVALUATION.TOTAL.LOSS' => ['Perdite', 'Losses', 'Verluste', 'Pertes'],
            'FINANCE.FX_REVALUATION.TOTAL.NET' => ['Risultato netto', 'Net result', 'Nettoergebnis', 'Résultat net'],
            'FINANCE.FX_REVALUATION.SECTION.HISTORY' => ['Rivalutazioni precedenti', 'Past revaluations', 'Frühere Bewertungen', 'Réévaluations précédentes'],
            'FINANCE.FX_REVALUATION.EMPTY.LINES' => ['Nessuna posizione in valuta da rivalutare per questo periodo.', 'No foreign currency position to revalue for this period.', 'Keine Fremdwährungsposition zur Bewertung in dieser Periode.', 'Aucune position en devises à réévaluer pour cette période.'],
            'FINANCE.FX_REVALUATION.EMPTY.HISTORY' => ['Nessuna rivalutazione eseguita.', 'No revaluation has been run yet.', 'Es wurde noch keine Bewertung durchgeführt.', 'Aucune réévaluation n\'a encore été exécutée.'],
            'FINANCE.FX_REVALUATION.MESSAGE.ALREADY_POSTED' => ['Questa rivalutazione risulta già registrata: lo stato qui sotto è quello aggiornato.', 'This revaluation has already been posted: the state below is the current one.', 'Diese Bewertung wurde bereits gebucht: Der Status unten ist der aktuelle.', 'Cette réévaluation a déjà été comptabilisée : l\'état ci-dessous est l\'état actuel.'],
            'FINANCE.FX_REVALUATION.MESSAGE.POSTED' => ['Rivalutazione registrata.', 'Revaluation posted.', 'Bewertung gebucht.', 'Réévaluation comptabilisée.'],
            'FINANCE.FX_REVALUATION.MESSAGE.REVERSED' => ['Rivalutazione stornata.', 'Revaluation reversed.', 'Bewertung storniert.', 'Réévaluation extournée.'],
            'FINANCE.FX_REVALUATION.MESSAGE.NOT_POSTED' => ['Solo una rivalutazione registrata può essere stornata.', 'Only a posted revaluation can be reversed.', 'Nur eine gebuchte Bewertung kann storniert werden.', 'Seule une réévaluation comptabilisée peut être extournée.'],
            'FINANCE.FX_REVALUATION.CONFIRM.REVERSE' => ['Stornare la rivalutazione? Verrà creata la scrittura inversa.', 'Reverse the revaluation? The opposite journal entry will be created.', 'Bewertung stornieren? Der Gegenbeleg wird erstellt.', 'Extourner la réévaluation ? L\'écriture inverse sera créée.'],
            'FINANCE.FX_REVALUATION.ERROR.SIMULATE_FAILED' => ['Impossibile simulare la rivalutazione.', 'The revaluation could not be simulated.', 'Die Bewertung konnte nicht simuliert werden.', 'La réévaluation n\'a pas pu être simulée.'],
            'FINANCE.FX_REVALUATION.ERROR.POST_FAILED' => ['Impossibile registrare la rivalutazione. Puoi riprovare: non verrà registrata due volte.', 'The revaluation could not be posted. You can retry: it will not be posted twice.', 'Die Bewertung konnte nicht gebucht werden. Sie können es erneut versuchen: Sie wird nicht doppelt gebucht.', 'La réévaluation n\'a pas pu être comptabilisée. Vous pouvez réessayer : elle ne sera pas comptabilisée deux fois.'],
            'FINANCE.FX_REVALUATION.ERROR.REVERSE_FAILED' => ['Impossibile stornare la rivalutazione.', 'The revaluation could not be reversed.', 'Die Bewertung konnte nicht storniert werden.', 'La réévaluation n\'a pas pu être extournée.'],
            'FINANCE.FX_REVALUATION.ERROR.LOAD_FAILED' => ['Impossibile aprire la rivalutazione.', 'The revaluation could not be opened.', 'Die Bewertung konnte nicht geöffnet werden.', 'La réévaluation n\'a pas pu être ouverte.'],
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
