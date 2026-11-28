<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the localization engine (Mod 002, issue #331): language settings, translation editor, master data translation tabs and document language actions.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 */
final class LocalizationFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::locales(),
            self::localizationSettings(),
            self::translationEditor(),
            self::translationImport(),
            self::masterData(),
            self::documents(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function locales(): array
    {
        return [
            'common.locale.it_CH' => ['Italiano (Svizzera)', 'Italian (Switzerland)', 'Italienisch (Schweiz)', 'Italien (Suisse)'],
            'common.locale.de_CH' => ['Tedesco (Svizzera)', 'German (Switzerland)', 'Deutsch (Schweiz)', 'Allemand (Suisse)'],
            'common.locale.fr_CH' => ['Francese (Svizzera)', 'French (Switzerland)', 'Französisch (Schweiz)', 'Français (Suisse)'],
            'common.locale.it_IT' => ['Italiano (Italia)', 'Italian (Italy)', 'Italienisch (Italien)', 'Italien (Italie)'],
            'common.locale.en' => ['Inglese', 'English', 'Englisch', 'Anglais'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function localizationSettings(): array
    {
        return [
            'settings.localization.nav' => ['Lingue e formati', 'Languages and formats', 'Sprachen und Formate', 'Langues et formats'],
            'settings.localization.title' => ['Lingue e formati regionali', 'Languages and regional formats', 'Sprachen und regionale Formate', 'Langues et formats régionaux'],
            'settings.localization.subtitle' => ['Scegli le lingue dell’azienda, la lingua predefinita e il formato di numeri e date.', 'Choose the company languages, the default language and the number and date formats.', 'Wählen Sie die Sprachen des Unternehmens, die Standardsprache sowie das Zahlen- und Datumsformat.', 'Choisissez les langues de l’entreprise, la langue par défaut et le format des nombres et des dates.'],
            'settings.localization.languages' => ['Lingue', 'Languages', 'Sprachen', 'Langues'],
            'settings.localization.active_locales' => ['Lingue attive', 'Active languages', 'Aktive Sprachen', 'Langues actives'],
            'settings.localization.default_locale' => ['Lingua predefinita', 'Default language', 'Standardsprache', 'Langue par défaut'],
            'settings.localization.formats' => ['Formati regionali', 'Regional formats', 'Regionale Formate', 'Formats régionaux'],
            'settings.localization.formats_hint' => ['Il formato regionale della lingua predefinita si applica se non scegli un valore diverso. L’API scambia sempre valori non formattati.', 'The regional format of the default language applies unless you pick a different value. The API always exchanges unformatted values.', 'Das regionale Format der Standardsprache gilt, sofern Sie keinen anderen Wert wählen. Die API tauscht stets unformatierte Werte aus.', 'Le format régional de la langue par défaut s’applique sauf si vous choisissez une autre valeur. L’API échange toujours des valeurs non formatées.'],
            'settings.localization.decimal_separator' => ['Separatore decimale', 'Decimal separator', 'Dezimaltrennzeichen', 'Séparateur décimal'],
            'settings.localization.thousands_separator' => ['Separatore delle migliaia', 'Thousands separator', 'Tausendertrennzeichen', 'Séparateur des milliers'],
            'settings.localization.date_format' => ['Formato della data', 'Date format', 'Datumsformat', 'Format de la date'],
            'settings.localization.regional_default' => ['Predefinito della regione', 'Regional default', 'Regionaler Standard', 'Valeur régionale par défaut'],
            'settings.localization.no_separator' => ['Nessuno', 'None', 'Keines', 'Aucun'],
            'settings.localization.space' => ['Spazio', 'Space', 'Leerzeichen', 'Espace'],
            'settings.localization.preview' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'settings.localization.save' => ['Salva impostazioni', 'Save settings', 'Einstellungen speichern', 'Enregistrer les paramètres'],
            'settings.localization.saved' => ['Impostazioni di localizzazione salvate.', 'Localization settings saved.', 'Lokalisierungseinstellungen gespeichert.', 'Paramètres de localisation enregistrés.'],
            'settings.localization.save_failed' => ['Impossibile salvare le impostazioni di localizzazione.', 'The localization settings could not be saved.', 'Die Lokalisierungseinstellungen konnten nicht gespeichert werden.', 'Les paramètres de localisation n’ont pas pu être enregistrés.'],
            'settings.localization.load_failed' => ['Impossibile caricare le impostazioni di localizzazione.', 'The localization settings could not be loaded.', 'Die Lokalisierungseinstellungen konnten nicht geladen werden.', 'Les paramètres de localisation n’ont pas pu être chargés.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function translationEditor(): array
    {
        return [
            'settings.translations.nav' => ['Traduzioni', 'Translations', 'Übersetzungen', 'Traductions'],
            'settings.translations.title' => ['Gestione traduzioni', 'Translation management', 'Übersetzungsverwaltung', 'Gestion des traductions'],
            'settings.translations.subtitle' => ['Modifica i testi dell’interfaccia per lingua. Le tue modifiche valgono solo per questa azienda.', 'Edit the interface texts per language. Your changes apply to this company only.', 'Bearbeiten Sie die Oberflächentexte pro Sprache. Ihre Änderungen gelten nur für dieses Unternehmen.', 'Modifiez les textes de l’interface par langue. Vos modifications ne valent que pour cette entreprise.'],
            'settings.translations.filter.locale' => ['Lingua', 'Language', 'Sprache', 'Langue'],
            'settings.translations.filter.group' => ['Gruppo', 'Group', 'Gruppe', 'Groupe'],
            'settings.translations.filter.missing_only' => ['Solo chiavi mancanti', 'Missing keys only', 'Nur fehlende Schlüssel', 'Clés manquantes uniquement'],
            'settings.translations.filter.search' => ['Cerca chiave o testo', 'Search key or text', 'Schlüssel oder Text suchen', 'Rechercher une clé ou un texte'],
            'settings.translations.missing_count' => ['{count, plural, =0 {Nessuna chiave mancante} one {# chiave mancante} other {# chiavi mancanti}}', '{count, plural, =0 {No missing keys} one {# missing key} other {# missing keys}}', '{count, plural, =0 {Keine fehlenden Schlüssel} one {# fehlender Schlüssel} other {# fehlende Schlüssel}}', '{count, plural, =0 {Aucune clé manquante} one {# clé manquante} other {# clés manquantes}}'],
            'settings.translations.column.key' => ['Chiave', 'Key', 'Schlüssel', 'Clé'],
            'settings.translations.column.locale' => ['Lingua', 'Language', 'Sprache', 'Langue'],
            'settings.translations.column.value' => ['Testo', 'Text', 'Text', 'Texte'],
            'settings.translations.column.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'settings.translations.locked' => ['Bloccata', 'Locked', 'Gesperrt', 'Verrouillée'],
            'settings.translations.deprecated' => ['Obsoleta', 'Deprecated', 'Veraltet', 'Obsolète'],
            'settings.translations.missing' => ['Mancante', 'Missing', 'Fehlt', 'Manquante'],
            'settings.translations.overridden' => ['Personalizzata', 'Customized', 'Angepasst', 'Personnalisée'],
            'settings.translations.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'settings.translations.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'settings.translations.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'settings.translations.saved' => ['Traduzione salvata.', 'Translation saved.', 'Übersetzung gespeichert.', 'Traduction enregistrée.'],
            'settings.translations.save_failed' => ['Impossibile salvare la traduzione.', 'The translation could not be saved.', 'Die Übersetzung konnte nicht gespeichert werden.', 'La traduction n’a pas pu être enregistrée.'],
            'settings.translations.locked_error' => ['Questa chiave è bloccata: contiene un testo di legge e non può essere modificata.', 'This key is locked: it holds a statutory text and cannot be changed.', 'Dieser Schlüssel ist gesperrt: Er enthält einen gesetzlichen Text und kann nicht geändert werden.', 'Cette clé est verrouillée : elle contient un texte légal et ne peut pas être modifiée.'],
            'settings.translations.load_failed' => ['Impossibile caricare le traduzioni.', 'The translations could not be loaded.', 'Die Übersetzungen konnten nicht geladen werden.', 'Les traductions n’ont pas pu être chargées.'],
            'settings.translations.export_xlsx' => ['Esporta XLSX', 'Export XLSX', 'XLSX exportieren', 'Exporter en XLSX'],
            'settings.translations.export_csv' => ['Esporta CSV', 'Export CSV', 'CSV exportieren', 'Exporter en CSV'],
            'settings.translations.export_failed' => ['Impossibile esportare le traduzioni.', 'The translations could not be exported.', 'Die Übersetzungen konnten nicht exportiert werden.', 'Les traductions n’ont pas pu être exportées.'],
            'settings.translations.import_action' => ['Importa traduzioni', 'Import translations', 'Übersetzungen importieren', 'Importer des traductions'],
            'settings.translations.empty' => ['Nessuna traduzione corrisponde ai filtri.', 'No translation matches the filters.', 'Keine Übersetzung entspricht den Filtern.', 'Aucune traduction ne correspond aux filtres.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function translationImport(): array
    {
        return [
            'settings.translations.import.title' => ['Importa traduzioni da file', 'Import translations from a file', 'Übersetzungen aus Datei importieren', 'Importer des traductions depuis un fichier'],
            'settings.translations.import.hint' => ['Carica un file XLSX o CSV con la colonna «key» e una colonna per ogni lingua. Prima viene mostrata un’anteprima delle differenze.', 'Upload an XLSX or CSV file with a “key” column and one column per language. A preview of the differences is shown first.', 'Laden Sie eine XLSX- oder CSV-Datei mit der Spalte «key» und einer Spalte pro Sprache hoch. Zuerst wird eine Vorschau der Unterschiede angezeigt.', 'Téléversez un fichier XLSX ou CSV avec la colonne « key » et une colonne par langue. Un aperçu des différences est d’abord affiché.'],
            'settings.translations.import.file' => ['File da importare', 'File to import', 'Zu importierende Datei', 'Fichier à importer'],
            'settings.translations.import.created' => ['{count, plural, one {# nuova} other {# nuove}}', '{count, plural, one {# new} other {# new}}', '{count, plural, one {# neu} other {# neu}}', '{count, plural, one {# nouvelle} other {# nouvelles}}'],
            'settings.translations.import.updated' => ['{count, plural, one {# modificata} other {# modificate}}', '{count, plural, one {# updated} other {# updated}}', '{count, plural, one {# geändert} other {# geändert}}', '{count, plural, one {# modifiée} other {# modifiées}}'],
            'settings.translations.import.unchanged' => ['{count, plural, one {# invariata} other {# invariate}}', '{count, plural, one {# unchanged} other {# unchanged}}', '{count, plural, one {# unverändert} other {# unverändert}}', '{count, plural, one {# inchangée} other {# inchangées}}'],
            'settings.translations.import.rejected' => ['{count, plural, one {# rifiutata} other {# rifiutate}}', '{count, plural, one {# rejected} other {# rejected}}', '{count, plural, one {# abgelehnt} other {# abgelehnt}}', '{count, plural, one {# rejetée} other {# rejetées}}'],
            'settings.translations.import.has_errors' => ['Il file contiene righe con errori: correggile e ricarica il file per poter confermare.', 'The file has rows with errors: fix them and upload the file again to confirm.', 'Die Datei enthält fehlerhafte Zeilen: Korrigieren Sie sie und laden Sie die Datei erneut hoch, um zu bestätigen.', 'Le fichier contient des lignes en erreur : corrigez-les et téléversez à nouveau le fichier pour confirmer.'],
            'settings.translations.import.row' => ['Riga', 'Row', 'Zeile', 'Ligne'],
            'settings.translations.import.old_value' => ['Valore attuale', 'Current value', 'Aktueller Wert', 'Valeur actuelle'],
            'settings.translations.import.new_value' => ['Nuovo valore', 'New value', 'Neuer Wert', 'Nouvelle valeur'],
            'settings.translations.import.action' => ['Azione', 'Action', 'Aktion', 'Action'],
            'settings.translations.import.action_create' => ['Nuova', 'New', 'Neu', 'Nouvelle'],
            'settings.translations.import.action_update' => ['Modificata', 'Updated', 'Geändert', 'Modifiée'],
            'settings.translations.import.action_unchanged' => ['Invariata', 'Unchanged', 'Unverändert', 'Inchangée'],
            'settings.translations.import.action_rejected' => ['Rifiutata', 'Rejected', 'Abgelehnt', 'Rejetée'],
            'settings.translations.import.action_blocked' => ['Bloccata', 'Blocked', 'Gesperrt', 'Bloquée'],
            'settings.translations.import.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'settings.translations.import.confirm' => ['Conferma importazione', 'Confirm import', 'Import bestätigen', 'Confirmer l’importation'],
            'settings.translations.import.done' => ['Traduzioni importate.', 'Translations imported.', 'Übersetzungen importiert.', 'Traductions importées.'],
            'settings.translations.import.locked' => ['Il file contiene chiavi bloccate (testi di legge) che non possono essere importate.', 'The file contains locked keys (statutory texts) that cannot be imported.', 'Die Datei enthält gesperrte Schlüssel (gesetzliche Texte), die nicht importiert werden können.', 'Le fichier contient des clés verrouillées (textes légaux) qui ne peuvent pas être importées.'],
            'settings.translations.import.failed' => ['Impossibile importare il file.', 'The file could not be imported.', 'Die Datei konnte nicht importiert werden.', 'Le fichier n’a pas pu être importé.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function masterData(): array
    {
        return [
            'translatable.tab' => ['Traduzioni', 'Translations', 'Übersetzungen', 'Traductions'],
            'translatable.title' => ['Traduzioni dei testi', 'Text translations', 'Textübersetzungen', 'Traductions des textes'],
            'translatable.completeness' => ['Completezza delle traduzioni: {percent}%', 'Translation completeness: {percent}%', 'Vollständigkeit der Übersetzungen: {percent}%', 'Complétude des traductions : {percent}%'],
            'translatable.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'translatable.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'translatable.field.invoice_clause' => ['Clausola in fattura', 'Invoice clause', 'Rechnungsklausel', 'Clause de facture'],
            'translatable.field.code' => ['Codice', 'Code', 'Code', 'Code'],
            'translatable.field.label' => ['Etichetta', 'Label', 'Bezeichnung', 'Libellé'],
            'translatable.field.title' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'translatable.field.text' => ['Testo', 'Text', 'Text', 'Texte'],
            'translatable.field.body' => ['Contenuto', 'Content', 'Inhalt', 'Contenu'],
            'translatable.save' => ['Salva traduzioni', 'Save translations', 'Übersetzungen speichern', 'Enregistrer les traductions'],
            'translatable.saved' => ['Traduzioni salvate.', 'Translations saved.', 'Übersetzungen gespeichert.', 'Traductions enregistrées.'],
            'translatable.load_failed' => ['Impossibile caricare le traduzioni del record.', 'The translations of this record could not be loaded.', 'Die Übersetzungen dieses Datensatzes konnten nicht geladen werden.', 'Les traductions de cet enregistrement n’ont pas pu être chargées.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function documents(): array
    {
        return [
            'document.locale.label' => ['Lingua del documento', 'Document language', 'Dokumentsprache', 'Langue du document'],
            'document.locale.default' => ['Predefinita dell’azienda', 'Company default', 'Standard des Unternehmens', 'Valeur par défaut de l’entreprise'],
            'document.locale.locked' => ['La lingua è bloccata: il documento è già stato emesso.', 'The language is locked: the document has already been issued.', 'Die Sprache ist gesperrt: Das Dokument wurde bereits ausgestellt.', 'La langue est verrouillée : le document a déjà été émis.'],
            'document.translation.print_italian' => ['Stampa traduzione italiana', 'Print Italian translation', 'Italienische Übersetzung drucken', 'Imprimer la traduction italienne'],
            'document.translation.print_failed' => ['Impossibile stampare la traduzione italiana.', 'The Italian translation could not be printed.', 'Die italienische Übersetzung konnte nicht gedruckt werden.', 'La traduction italienne n’a pas pu être imprimée.'],
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
