<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of multi-level BOM management (Mod 060): tree designer with EUR.1 panel, where-used impact analyzer with mass
 * replace, ECO revision diff viewer and the BOM importer wizard. Each entry is [it, en, de, fr]. Placeholders are written {name}.
 */
final class BomManagementFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.MFG_BOM_DESIGNER' => ['Designer distinta base', 'BOM designer', 'Stücklisten-Designer', 'Concepteur de nomenclature'],
            'NAV.MFG_WHERE_USED' => ['Dove usato', 'Where-used analysis', 'Verwendungsnachweis', 'Utilisation (where-used)'],
            'NAV.MFG_BOM_DIFF' => ['Differenze revisioni', 'Revision diff', 'Revisionsvergleich', 'Différences de révision'],
            'NAV.MFG_BOM_IMPORT' => ['Importa distinta base', 'Import BOM', 'Stückliste importieren', 'Importer une nomenclature'],

            'MFG_BOM.DESIGNER_TITLE' => ['Designer distinta base', 'BOM designer', 'Stücklisten-Designer', 'Concepteur de nomenclature'],
            'MFG_BOM.DESIGNER_SUBTITLE' => [
                'Esplosione multilivello, costo a cascata e modifica in linea: ogni modifica diventa una modifica ingegneristica (ECO).',
                'Multi-level explosion, cost rollup and inline editing: every change becomes an engineering change (ECO).',
                'Mehrstufige Auflösung, Kostenaufrollung und Bearbeitung direkt in der Tabelle: jede Änderung wird zu einer technischen Änderung (ECO).',
                'Éclatement multiniveau, cumul des coûts et édition en ligne : chaque modification devient une modification d’ingénierie (ECO).',
            ],
            'MFG_BOM.SELECT_BOM' => ['Seleziona una distinta base', 'Select a BOM', 'Stückliste auswählen', 'Sélectionner une nomenclature'],
            'MFG_BOM.PICK_A_BOM' => ['Scegli una distinta base per vederne l’albero.', 'Pick a BOM to see its tree.', 'Wählen Sie eine Stückliste, um ihre Struktur zu sehen.', 'Choisissez une nomenclature pour voir son arborescence.'],
            'MFG_BOM.QUANTITY' => ['Quantità da produrre', 'Quantity to build', 'Zu fertigende Menge', 'Quantité à produire'],
            'MFG_BOM.LOAD' => ['Carica', 'Load', 'Laden', 'Charger'],
            'MFG_BOM.LOAD_ERROR' => ['Impossibile caricare l’esplosione della distinta', 'The BOM explosion could not be loaded', 'Die Stücklistenauflösung konnte nicht geladen werden', 'L’éclatement de la nomenclature n’a pas pu être chargé'],
            'MFG_BOM.EXPAND_ALL' => ['Espandi tutto', 'Expand all', 'Alle aufklappen', 'Tout développer'],
            'MFG_BOM.COLLAPSE_ALL' => ['Comprimi tutto', 'Collapse all', 'Alle zuklappen', 'Tout réduire'],
            'MFG_BOM.TOGGLE' => ['Espandi o comprimi il livello', 'Expand or collapse the level', 'Ebene auf- oder zuklappen', 'Développer ou réduire le niveau'],
            'MFG_BOM.TOTAL_COST' => ['Costo totale', 'Total cost', 'Gesamtkosten', 'Coût total'],
            'MFG_BOM.LEAD_TIME_DAYS' => ['Lead time totale (giorni)', 'Total lead time (days)', 'Gesamtdurchlaufzeit (Tage)', 'Délai total (jours)'],
            'MFG_BOM.COMPONENTS' => ['Righe', 'Lines', 'Positionen', 'Lignes'],
            'MFG_BOM.PHANTOM' => ['Fantasma', 'Phantom', 'Phantom', 'Fantôme'],
            'MFG_BOM.VIA_PHANTOM' => ['Da fantasma', 'Via phantom', 'Über Phantom', 'Via fantôme'],

            'MFG_BOM.COL_SEQUENCE' => ['Sequenza', 'Sequence', 'Reihenfolge', 'Séquence'],
            'MFG_BOM.COL_COMPONENT' => ['Componente', 'Component', 'Komponente', 'Composant'],
            'MFG_BOM.COL_QUANTITY_PER' => ['Quantità per', 'Quantity per', 'Menge pro', 'Quantité par'],
            'MFG_BOM.COL_SCRAP' => ['Scarto %', 'Scrap %', 'Ausschuss %', 'Rebut %'],
            'MFG_BOM.COL_YIELD' => ['Resa %', 'Yield %', 'Ausbeute %', 'Rendement %'],
            'MFG_BOM.COL_FIXED_WASTE' => ['Scarto fisso', 'Fixed waste', 'Fixer Ausschuss', 'Rebut fixe'],
            'MFG_BOM.COL_EFFECTIVE' => ['Quantità effettiva', 'Effective quantity', 'Effektive Menge', 'Quantité effective'],
            'MFG_BOM.COL_UNIT_COST' => ['Costo unitario', 'Unit cost', 'Stückkosten', 'Coût unitaire'],
            'MFG_BOM.COL_COST' => ['Costo', 'Cost', 'Kosten', 'Coût'],
            'MFG_BOM.COL_LEAD_TIME' => ['Anticipo (gg)', 'Lead offset (d)', 'Vorlauf (T)', 'Décalage (j)'],
            'MFG_BOM.COL_ISSUE_METHOD' => ['Metodo di prelievo', 'Issue method', 'Entnahmemethode', 'Méthode de sortie'],
            'MFG_BOM.COL_OPERATION' => ['Operazione', 'Operation', 'Arbeitsgang', 'Opération'],
            'MFG_BOM.COL_SUBSTITUTES' => ['Sostituti', 'Substitutes', 'Ersatzartikel', 'Substituts'],
            'MFG_BOM.MOVE_UP' => ['Sposta su', 'Move up', 'Nach oben', 'Monter'],
            'MFG_BOM.MOVE_DOWN' => ['Sposta giù', 'Move down', 'Nach unten', 'Descendre'],

            'MFG_BOM.ECO_REASON' => ['Motivo della modifica ingegneristica', 'Reason for the engineering change', 'Grund der technischen Änderung', 'Motif de la modification d’ingénierie'],
            'MFG_BOM.SAVE_AS_ECO' => ['Salva come ECO', 'Save as ECO', 'Als ECO speichern', 'Enregistrer comme ECO'],
            'MFG_BOM.DISCARD' => ['Annulla modifiche', 'Discard edits', 'Änderungen verwerfen', 'Annuler les modifications'],
            'MFG_BOM.PENDING_CHANGES' => ['Modifiche non salvate', 'Unsaved changes', 'Nicht gespeicherte Änderungen', 'Modifications non enregistrées'],
            'MFG_BOM.ECO_OPENED' => ['Modifica ingegneristica aperta: attende l’approvazione', 'Engineering change opened: it awaits approval', 'Technische Änderung eröffnet: wartet auf Freigabe', 'Modification d’ingénierie ouverte : en attente d’approbation'],
            'MFG_BOM.SAVE_ERROR' => ['Impossibile aprire la modifica ingegneristica', 'The engineering change could not be opened', 'Die technische Änderung konnte nicht eröffnet werden', 'La modification d’ingénierie n’a pas pu être ouverte'],

            'MFG_BOM.EUR1_TITLE' => ['Origine preferenziale (EUR.1)', 'Preferential origin (EUR.1)', 'Präferenzursprung (EUR.1)', 'Origine préférentielle (EUR.1)'],
            'MFG_BOM.EUR1_THRESHOLD' => ['Soglia materiali non originari %', 'Non-originating materials threshold %', 'Schwelle Nicht-Ursprungsmaterial %', 'Seuil de matières non originaires %'],
            'MFG_BOM.EUR1_CHECK' => ['Verifica origine', 'Check origin', 'Ursprung prüfen', 'Vérifier l’origine'],
            'MFG_BOM.EUR1_QUALIFIES' => ['Il prodotto ha origine preferenziale', 'The product qualifies for preferential origin', 'Das Erzeugnis hat Präferenzursprung', 'Le produit a l’origine préférentielle'],
            'MFG_BOM.EUR1_NOT_QUALIFIED' => ['Il prodotto non ha origine preferenziale', 'The product does not qualify for preferential origin', 'Das Erzeugnis hat keinen Präferenzursprung', 'Le produit n’a pas l’origine préférentielle'],
            'MFG_BOM.EUR1_NON_ORIGINATING' => ['Materiali non originari', 'Non-originating materials', 'Nicht-Ursprungsmaterial', 'Matières non originaires'],
            'MFG_BOM.EUR1_ERROR' => ['Impossibile verificare l’origine preferenziale', 'The preferential origin could not be checked', 'Der Präferenzursprung konnte nicht geprüft werden', 'L’origine préférentielle n’a pas pu être vérifiée'],

            'MFG_BOM.WHERE_USED_TITLE' => ['Dove usato e impatto', 'Where-used and impact', 'Verwendungsnachweis und Auswirkung', 'Utilisation et impact'],
            'MFG_BOM.WHERE_USED_SUBTITLE' => [
                'Cerca un articolo, vedi tutti i prodotti che lo consumano e sostituiscilo in blocco con una simulazione preventiva.',
                'Search an item, see every product that consumes it and replace it in bulk after a dry run.',
                'Artikel suchen, alle verbrauchenden Erzeugnisse sehen und den Artikel nach einer Simulation gesammelt ersetzen.',
                'Recherchez un article, voyez tous les produits qui le consomment et remplacez-le en masse après une simulation.',
            ],
            'MFG_BOM.SEARCH_ITEM' => ['Cerca un articolo', 'Search an item', 'Artikel suchen', 'Rechercher un article'],
            'MFG_BOM.ANALYZE' => ['Analizza impatto', 'Analyze impact', 'Auswirkung analysieren', 'Analyser l’impact'],
            'MFG_BOM.ANALYZE_ERROR' => ['Impossibile analizzare l’utilizzo dell’articolo', 'The usage of the item could not be analyzed', 'Die Verwendung des Artikels konnte nicht analysiert werden', 'L’utilisation de l’article n’a pas pu être analysée'],
            'MFG_BOM.NO_PARENTS' => ['Nessuna distinta usa questo articolo', 'No BOM uses this item', 'Keine Stückliste verwendet diesen Artikel', 'Aucune nomenclature n’utilise cet article'],
            'MFG_BOM.UPWARD_TREE' => ['Albero verso l’alto', 'Upward tree', 'Aufwärtsstruktur', 'Arborescence ascendante'],
            'MFG_BOM.IMPACT_SUMMARY' => ['{parents} assiemi coinvolti, di cui {top} prodotti di livello superiore.', '{parents} assemblies affected, {top} of them top-level products.', '{parents} Baugruppen betroffen, davon {top} Endprodukte.', '{parents} assemblages concernés, dont {top} produits de premier niveau.'],
            'MFG_BOM.COL_PARENT' => ['Assieme padre', 'Parent assembly', 'Übergeordnete Baugruppe', 'Assemblage parent'],
            'MFG_BOM.COL_PATH' => ['Percorso', 'Path', 'Pfad', 'Chemin'],
            'MFG_BOM.COL_COEFFICIENT' => ['Coefficiente cumulato', 'Cumulative coefficient', 'Kumulierter Koeffizient', 'Coefficient cumulé'],
            'MFG_BOM.TOP_LEVEL' => ['Livello superiore', 'Top level', 'Oberste Ebene', 'Premier niveau'],
            'MFG_BOM.MASS_REPLACE' => ['Sostituzione di massa', 'Mass replace', 'Massenersatz', 'Remplacement en masse'],
            'MFG_BOM.SUBSTITUTE_ITEM' => ['Articolo sostitutivo', 'Substitute item', 'Ersatzartikel', 'Article de remplacement'],
            'MFG_BOM.REPLACE_REASON' => ['Motivo della sostituzione', 'Reason for the replacement', 'Grund des Ersatzes', 'Motif du remplacement'],
            'MFG_BOM.EFFECTIVE_FROM' => ['In vigore dal', 'Effective from', 'Gültig ab', 'En vigueur à partir du'],
            'MFG_BOM.DRY_RUN' => ['Simula (dry run)', 'Simulate (dry run)', 'Simulieren (Dry-Run)', 'Simuler (dry run)'],
            'MFG_BOM.RUN_REPLACE' => ['Esegui sostituzione', 'Run replacement', 'Ersatz ausführen', 'Exécuter le remplacement'],
            'MFG_BOM.DRY_RUN_RESULT' => ['Simulazione: {replaced} distinte da sostituire, {skipped} saltate.', 'Dry run: {replaced} BOMs would be replaced, {skipped} skipped.', 'Simulation: {replaced} Stücklisten würden ersetzt, {skipped} übersprungen.', 'Simulation : {replaced} nomenclatures seraient remplacées, {skipped} ignorées.'],
            'MFG_BOM.REPLACE_DONE' => ['Sostituzione eseguita: {replaced} distinte aggiornate, {skipped} saltate.', 'Replacement done: {replaced} BOMs updated, {skipped} skipped.', 'Ersatz ausgeführt: {replaced} Stücklisten aktualisiert, {skipped} übersprungen.', 'Remplacement effectué : {replaced} nomenclatures mises à jour, {skipped} ignorées.'],
            'MFG_BOM.REPLACED' => ['Sostituite', 'Replaced', 'Ersetzt', 'Remplacées'],
            'MFG_BOM.SKIPPED' => ['Saltate', 'Skipped', 'Übersprungen', 'Ignorées'],
            'MFG_BOM.REPLACE_ERROR' => ['Impossibile eseguire la sostituzione', 'The replacement could not be run', 'Der Ersatz konnte nicht ausgeführt werden', 'Le remplacement n’a pas pu être exécuté'],

            'MFG_BOM.DIFF_TITLE' => ['Differenze tra revisioni', 'Revision diff', 'Revisionsvergleich', 'Différences entre révisions'],
            'MFG_BOM.DIFF_SUBTITLE' => [
                'Componenti aggiunti, rimossi e modificati, con il valore prima e dopo.',
                'Added, removed and modified components with the value before and after.',
                'Hinzugefügte, entfernte und geänderte Komponenten mit Wert vorher und nachher.',
                'Composants ajoutés, supprimés et modifiés, avec la valeur avant et après.',
            ],
            'MFG_BOM.DIFF_EMPTY' => ['Nessuna differenza', 'No differences', 'Keine Unterschiede', 'Aucune différence'],
            'MFG_BOM.DIFF_NO_SOURCE' => ['Apri questa pagina da una modifica ingegneristica o da due distinte da confrontare.', 'Open this page from an engineering change or from two BOMs to compare.', 'Öffnen Sie diese Seite aus einer technischen Änderung oder mit zwei zu vergleichenden Stücklisten.', 'Ouvrez cette page depuis une modification d’ingénierie ou avec deux nomenclatures à comparer.'],
            'MFG_BOM.DIFF_LOAD_ERROR' => ['Impossibile caricare le differenze', 'The differences could not be loaded', 'Die Unterschiede konnten nicht geladen werden', 'Les différences n’ont pas pu être chargées'],
            'MFG_BOM.DIFF_LAYOUT' => ['Vista delle differenze', 'Diff layout', 'Darstellung der Unterschiede', 'Affichage des différences'],
            'MFG_BOM.DIFF_UNIFIED' => ['Unificata', 'Unified', 'Einheitlich', 'Unifiée'],
            'MFG_BOM.DIFF_SIDE_BY_SIDE' => ['Affiancata', 'Side by side', 'Nebeneinander', 'Côte à côte'],
            'MFG_BOM.DIFF_ADDED' => ['Aggiunti', 'Added', 'Hinzugefügt', 'Ajoutés'],
            'MFG_BOM.DIFF_REMOVED' => ['Rimossi', 'Removed', 'Entfernt', 'Supprimés'],
            'MFG_BOM.DIFF_MODIFIED' => ['Modificati', 'Modified', 'Geändert', 'Modifiés'],
            'MFG_BOM.DIFF_CHANGE' => ['Modifica', 'Change', 'Änderung', 'Modification'],
            'MFG_BOM.DIFF_FIELD' => ['Campo', 'Field', 'Feld', 'Champ'],
            'MFG_BOM.DIFF_CHANGE_VALUE' => ['Valore', 'Value', 'Wert', 'Valeur'],
            'MFG_BOM.DIFF_BEFORE' => ['Prima', 'Before', 'Vorher', 'Avant'],
            'MFG_BOM.DIFF_AFTER' => ['Dopo', 'After', 'Nachher', 'Après'],

            'MFG_BOM.IMPORT_TITLE' => ['Importa distinta base', 'Import BOM', 'Stückliste importieren', 'Importer une nomenclature'],
            'MFG_BOM.IMPORT_SUBTITLE' => [
                'Carica un file CSV o Excel, abbina le colonne, controlla ogni riga con una simulazione e crea la distinta in bozza.',
                'Upload a CSV or Excel file, map its columns, check every row with a dry run and create the draft BOM.',
                'CSV- oder Excel-Datei hochladen, Spalten zuordnen, jede Zeile per Simulation prüfen und die Stückliste als Entwurf anlegen.',
                'Chargez un fichier CSV ou Excel, associez les colonnes, vérifiez chaque ligne par simulation et créez la nomenclature en brouillon.',
            ],
            'MFG_BOM.IMPORT_STEP_UPLOAD' => ['Carica file', 'Upload file', 'Datei hochladen', 'Charger le fichier'],
            'MFG_BOM.IMPORT_STEP_MAP' => ['Abbina colonne', 'Map columns', 'Spalten zuordnen', 'Associer les colonnes'],
            'MFG_BOM.IMPORT_STEP_CHECK' => ['Controllo righe', 'Check rows', 'Zeilen prüfen', 'Contrôle des lignes'],
            'MFG_BOM.IMPORT_STEP_COMMIT' => ['Crea distinta', 'Create BOM', 'Stückliste anlegen', 'Créer la nomenclature'],
            'MFG_BOM.PRODUCT_CODE' => ['Codice prodotto finito', 'Finished product code', 'Code des Fertigerzeugnisses', 'Code du produit fini'],
            'MFG_BOM.BOM_NAME' => ['Nome distinta', 'BOM name', 'Stücklistenname', 'Nom de la nomenclature'],
            'MFG_BOM.IMPORT_FILE' => ['File CSV o Excel (.xlsx)', 'CSV or Excel (.xlsx) file', 'CSV- oder Excel-Datei (.xlsx)', 'Fichier CSV ou Excel (.xlsx)'],
            'MFG_BOM.IMPORT_FILE_READ' => ['{file}: {rows} righe lette', '{file}: {rows} rows read', '{file}: {rows} Zeilen gelesen', '{file} : {rows} lignes lues'],
            'MFG_BOM.IMPORT_PARSE_ERROR' => ['Impossibile leggere il file', 'The file could not be read', 'Die Datei konnte nicht gelesen werden', 'Le fichier n’a pas pu être lu'],
            'MFG_BOM.IMPORT_MAP_HINT' => ['Indica quale colonna del file corrisponde a ogni campo. I campi con * sono obbligatori.', 'Choose which column of the file matches each field. Fields marked * are required.', 'Wählen Sie, welche Spalte der Datei zu jedem Feld gehört. Mit * markierte Felder sind Pflicht.', 'Indiquez quelle colonne du fichier correspond à chaque champ. Les champs marqués * sont obligatoires.'],
            'MFG_BOM.IMPORT_NOT_MAPPED' => ['Non abbinato', 'Not mapped', 'Nicht zugeordnet', 'Non associé'],
            'MFG_BOM.IMPORT_COLUMN' => ['Colonna', 'Column', 'Spalte', 'Colonne'],
            'MFG_BOM.IMPORT_MISSING_REQUIRED' => ['Abbina almeno codice articolo e quantità.', 'Map at least the item code and the quantity.', 'Ordnen Sie mindestens Artikelcode und Menge zu.', 'Associez au moins le code article et la quantité.'],
            'MFG_BOM.IMPORT_FIELD_ITEM_CODE' => ['Codice articolo', 'Item code', 'Artikelcode', 'Code article'],
            'MFG_BOM.IMPORT_FIELD_QUANTITY_PER' => ['Quantità per', 'Quantity per', 'Menge pro', 'Quantité par'],
            'MFG_BOM.IMPORT_FIELD_UOM' => ['Unità di misura', 'Unit of measure', 'Mengeneinheit', 'Unité de mesure'],
            'MFG_BOM.IMPORT_FIELD_SCRAP_PERCENTAGE' => ['Scarto %', 'Scrap %', 'Ausschuss %', 'Rebut %'],
            'MFG_BOM.IMPORT_FIELD_YIELD_PERCENTAGE' => ['Resa %', 'Yield %', 'Ausbeute %', 'Rendement %'],
            'MFG_BOM.IMPORT_FIELD_FIXED_SCRAP_QUANTITY' => ['Scarto fisso', 'Fixed scrap', 'Fixer Ausschuss', 'Rebut fixe'],
            'MFG_BOM.IMPORT_FIELD_OPERATION_SEQUENCE' => ['Sequenza operazione', 'Operation sequence', 'Arbeitsgangfolge', 'Séquence d’opération'],
            'MFG_BOM.IMPORT_FIELD_NOTES' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'MFG_BOM.IMPORT_RUN_CHECK' => ['Controlla le righe', 'Check the rows', 'Zeilen prüfen', 'Contrôler les lignes'],
            'MFG_BOM.IMPORT_VALID' => ['Tutte le {rows} righe sono valide.', 'All {rows} rows are valid.', 'Alle {rows} Zeilen sind gültig.', 'Les {rows} lignes sont toutes valides.'],
            'MFG_BOM.IMPORT_INVALID' => ['{errors} righe su {rows} hanno errori: correggi il file e riprova.', '{errors} of {rows} rows have errors: fix the file and try again.', '{errors} von {rows} Zeilen enthalten Fehler: Datei korrigieren und erneut versuchen.', '{errors} lignes sur {rows} contiennent des erreurs : corrigez le fichier et réessayez.'],
            'MFG_BOM.IMPORT_LINE' => ['Riga', 'Line', 'Zeile', 'Ligne'],
            'MFG_BOM.IMPORT_STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'MFG_BOM.IMPORT_ERRORS' => ['Errori', 'Errors', 'Fehler', 'Erreurs'],
            'MFG_BOM.IMPORT_COMMIT_HINT' => ['La distinta viene creata in bozza e va attivata prima di usarla negli ordini.', 'The BOM is created as a draft and must be activated before production orders use it.', 'Die Stückliste wird als Entwurf angelegt und muss aktiviert werden, bevor Fertigungsaufträge sie verwenden.', 'La nomenclature est créée en brouillon et doit être activée avant d’être utilisée par les ordres de fabrication.'],
            'MFG_BOM.IMPORT_STALE' => ['I dati sono cambiati dopo il controllo: ripeti il controllo delle righe.', 'The data changed after the check: run the row check again.', 'Die Daten haben sich nach der Prüfung geändert: Zeilenprüfung wiederholen.', 'Les données ont changé après le contrôle : relancez le contrôle des lignes.'],
            'MFG_BOM.IMPORT_COMMIT_REFUSED' => ['Il server ha rifiutato la creazione: correggi le righe con errori.', 'The server refused the creation: fix the rows with errors.', 'Der Server hat das Anlegen abgelehnt: Zeilen mit Fehlern korrigieren.', 'Le serveur a refusé la création : corrigez les lignes en erreur.'],
            'MFG_BOM.IMPORT_COMMIT' => ['Crea distinta in bozza', 'Create draft BOM', 'Stückliste als Entwurf anlegen', 'Créer la nomenclature en brouillon'],
            'MFG_BOM.IMPORT_DONE' => ['Distinta creata in bozza', 'Draft BOM created', 'Stückliste als Entwurf angelegt', 'Nomenclature créée en brouillon'],
            'MFG_BOM.IMPORT_ANOTHER' => ['Importa un’altra distinta', 'Import another BOM', 'Weitere Stückliste importieren', 'Importer une autre nomenclature'],
            'MFG_BOM.IMPORT_ERROR' => ['Impossibile completare l’importazione', 'The import could not be completed', 'Der Import konnte nicht abgeschlossen werden', 'L’importation n’a pas pu être effectuée'],
            'MFG_BOM.IMPORT_BACK' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'MFG_BOM.IMPORT_NEXT' => ['Avanti', 'Next', 'Weiter', 'Suivant'],
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
