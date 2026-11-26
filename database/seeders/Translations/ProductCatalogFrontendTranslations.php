<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the product catalog (Mod 052): the product list and editor (general data, CH / IT classification, variant matrix,
 * kit bill of materials, unit hierarchy and converter), the attributes, the bulk import / export, the codes the API reports
 * (productCatalog.error.<code>, taken from lang/<locale>/product_catalog.php so there is one source for each text) and the names of
 * the units of measure. Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French
 * text fr-CH. Legal and regulatory acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, TARIC, Tares, EAN, GTIN, SKU) stay
 * untranslated.
 */
final class ProductCatalogFrontendTranslations
{
    private const array LOCALES = ['it', 'en', 'de', 'fr'];

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::units(),
            self::errors(),
            self::fields(),
            self::columns(),
            self::actions(),
            self::sections(),
            self::hints(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.PRODUCT_CATALOG' => ['Catalogo prodotti', 'Product catalog', 'Produktkatalog', 'Catalogue produits'],
            'NAV.PRODUCT_CATALOG_PRODUCTS' => ['Prodotti e varianti', 'Products and variants', 'Produkte und Varianten', 'Produits et variantes'],
            'NAV.PRODUCT_CATALOG_ATTRIBUTES' => ['Attributi', 'Attributes', 'Attribute', 'Attributs'],
            'NAV.PRODUCT_CATALOG_TRANSFER' => ['Import / export', 'Import / export', 'Import / Export', 'Import / export'],
            'PAGE_TITLE.PRODUCT_CATALOG' => ['Catalogo prodotti, varianti e kit', 'Product catalog, variants and kits', 'Produktkatalog, Varianten und Sets', 'Catalogue produits, variantes et kits'],
            'PAGE_TITLE.PRODUCT_CATALOG_ATTRIBUTES' => ['Attributi delle varianti', 'Variant attributes', 'Variantenattribute', 'Attributs des variantes'],
            'PAGE_TITLE.PRODUCT_CATALOG_TRANSFER' => ['Importazione ed esportazione del catalogo', 'Catalog import and export', 'Import und Export des Katalogs', 'Importation et exportation du catalogue'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'productCatalog.productType.storable' => ['Articolo a magazzino', 'Storable', 'Lagerartikel', 'Article stocké'],
            'productCatalog.productType.consumable' => ['Consumabile', 'Consumable', 'Verbrauchsmaterial', 'Consommable'],
            'productCatalog.productType.service' => ['Servizio', 'Service', 'Dienstleistung', 'Service'],
            'productCatalog.productType.digital' => ['Digitale', 'Digital', 'Digital', 'Numérique'],
            'productCatalog.kitType.phantom' => ['Kit fantasma', 'Phantom kit', 'Phantom-Set', 'Kit fantôme'],
            'productCatalog.kitType.physical' => ['Kit fisico', 'Physical kit', 'Physisches Set', 'Kit physique'],
            'productCatalog.role.purchase' => ['Acquisto', 'Purchase', 'Einkauf', 'Achat'],
            'productCatalog.role.sales' => ['Vendita', 'Sales', 'Verkauf', 'Vente'],
            'productCatalog.role.logistic' => ['Logistica', 'Logistics', 'Logistik', 'Logistique'],
            'productCatalog.rounding.half_up' => ['Al più vicino (0,5 verso l\'alto)', 'Nearest (half up)', 'Kaufmännisch runden', 'Au plus proche (0,5 vers le haut)'],
            'productCatalog.rounding.up' => ['Sempre per eccesso', 'Always up', 'Immer aufrunden', 'Toujours vers le haut'],
            'productCatalog.rounding.down' => ['Sempre per difetto', 'Always down', 'Immer abrunden', 'Toujours vers le bas'],
            'productCatalog.rounding.none' => ['Nessuno (solo quantità esatte)', 'None (exact quantities only)', 'Keine (nur exakte Mengen)', 'Aucun (quantités exactes uniquement)'],
            'productCatalog.deltaType.fixed' => ['Importo fisso', 'Fixed amount', 'Fixbetrag', 'Montant fixe'],
            'productCatalog.deltaType.percent' => ['Percentuale', 'Percent', 'Prozent', 'Pourcentage'],
            'productCatalog.mode.dry_run' => ['Solo verifica', 'Check only', 'Nur prüfen', 'Vérification seule'],
            'productCatalog.mode.atomic' => ['Tutto o niente', 'All or nothing', 'Alles oder nichts', 'Tout ou rien'],
            'productCatalog.mode.partial' => ['Salva le righe valide', 'Save the valid rows', 'Gültige Zeilen speichern', 'Enregistrer les lignes valides'],
            'productCatalog.kind.catalog' => ['Prodotti e padri', 'Products and parents', 'Produkte und Eltern', 'Produits et parents'],
            'productCatalog.kind.variants' => ['Solo varianti', 'Variants only', 'Nur Varianten', 'Variantes seules'],
            'productCatalog.kind.all' => ['Tutto', 'Everything', 'Alles', 'Tout'],
            'productCatalog.status.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'productCatalog.status.inactive' => ['Non attivo', 'Inactive', 'Inaktiv', 'Inactif'],
            'productCatalog.status.exists' => ['Già presente', 'Already exists', 'Bereits vorhanden', 'Existe déjà'],
            'productCatalog.status.new' => ['Nuova', 'New', 'Neu', 'Nouvelle'],
            'productCatalog.status.overridden' => ['Prezzo proprio', 'Own price', 'Eigener Preis', 'Prix propre'],
            'productCatalog.status.followsParent' => ['Segue il padre', 'Follows the parent', 'Folgt dem Elternprodukt', 'Suit le parent'],
        ];
    }

    /**
     * Names of the units of measure seeded for the demo company (the stored name is the fallback for any other unit).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function units(): array
    {
        return [
            'productCatalog.unit.base' => ['Unità base', 'Base unit', 'Basiseinheit', 'Unité de base'],
            'productCatalog.unit.PZ' => ['Pezzo', 'Piece', 'Stück', 'Pièce'],
            'productCatalog.unit.PACK' => ['Confezione', 'Pack', 'Packung', 'Paquet'],
            'productCatalog.unit.BOX' => ['Scatola', 'Box', 'Schachtel', 'Boîte'],
            'productCatalog.unit.CT' => ['Cartone', 'Carton', 'Karton', 'Carton'],
            'productCatalog.unit.PLT' => ['Pallet', 'Pallet', 'Palette', 'Palette'],
            'productCatalog.unit.SET' => ['Set', 'Set', 'Set', 'Set'],
            'productCatalog.unit.KG' => ['Chilogrammo', 'Kilogram', 'Kilogramm', 'Kilogramme'],
            'productCatalog.unit.G' => ['Grammo', 'Gram', 'Gramm', 'Gramme'],
            'productCatalog.unit.L' => ['Litro', 'Litre', 'Liter', 'Litre'],
            'productCatalog.unit.ML' => ['Millilitro', 'Millilitre', 'Milliliter', 'Millilitre'],
            'productCatalog.unit.M' => ['Metro', 'Metre', 'Meter', 'Mètre'],
            'productCatalog.unit.H' => ['Ora', 'Hour', 'Stunde', 'Heure'],
            'productCatalog.unit.LIC' => ['Licenza', 'Licence', 'Lizenz', 'Licence'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        $dictionaries = array_map(
            static fn (string $locale): array => (array) require lang_path($locale.'/product_catalog.php'),
            self::LOCALES,
        );
        $result = [
            'productCatalog.error.generic' => ['Operazione non riuscita. Riprova.', 'The operation failed. Try again.', 'Der Vorgang ist fehlgeschlagen. Versuchen Sie es erneut.', 'L\'opération a échoué. Réessayez.'],
        ];

        foreach (array_keys($dictionaries[1]['errors']) as $code) {
            $texts = array_map(static fn (array $dictionary): string => (string) preg_replace('/:(\w+)/', '{$1}', (string) $dictionary['errors'][$code]), $dictionaries);
            $result['productCatalog.error.'.$code] = [$texts[0], $texts[1], $texts[2], $texts[3]];
        }

        return $result;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'productCatalog.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'productCatalog.field.sku' => ['SKU', 'SKU', 'SKU', 'SKU'],
            'productCatalog.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'productCatalog.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'productCatalog.field.productType' => ['Tipo di articolo', 'Item type', 'Artikeltyp', 'Type d\'article'],
            'productCatalog.field.kitType' => ['Kit', 'Kit', 'Set', 'Kit'],
            'productCatalog.field.kind' => ['Mostra', 'Show', 'Anzeigen', 'Afficher'],
            'productCatalog.field.stockUnit' => ['Unità di magazzino (base)', 'Stock unit (base)', 'Lagereinheit (Basis)', 'Unité de stock (base)'],
            'productCatalog.field.costPrice' => ['Costo base', 'Base cost', 'Basiskosten', 'Coût de base'],
            'productCatalog.field.salePrice' => ['Prezzo di listino', 'List price', 'Listenpreis', 'Prix catalogue'],
            'productCatalog.field.taxRate' => ['Aliquota IVA %', 'VAT rate %', 'MwSt-Satz %', 'Taux de TVA %'],
            'productCatalog.field.barcode' => ['Codice a barre (EAN / GTIN)', 'Barcode (EAN / GTIN)', 'Barcode (EAN / GTIN)', 'Code-barres (EAN / GTIN)'],
            'productCatalog.field.generateBarcode' => ['Genera un EAN-13 interno', 'Generate an in-house EAN-13', 'Internen EAN-13 erzeugen', 'Générer un EAN-13 interne'],
            'productCatalog.field.generateBarcodes' => ['Genera un EAN-13 interno per ogni variante', 'Generate an in-house EAN-13 for every variant', 'Internen EAN-13 für jede Variante erzeugen', 'Générer un EAN-13 interne pour chaque variante'],
            'productCatalog.field.trackInventory' => ['Gestisci le giacenze', 'Track inventory', 'Bestand führen', 'Suivre les stocks'],
            'productCatalog.field.isActive' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'productCatalog.field.category' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'productCatalog.field.brand' => ['Marchio', 'Brand', 'Marke', 'Marque'],
            'productCatalog.field.tares' => ['Voce di tariffa svizzera (Tares, 8 cifre)', 'Swiss tariff number (Tares, 8 digits)', 'Schweizer Zolltarifnummer (Tares, 8 Stellen)', 'Numéro du tarif douanier suisse (Tares, 8 chiffres)'],
            'productCatalog.field.taric' => ['Nomenclatura combinata / TARIC (6, 8 o 10 cifre)', 'Combined Nomenclature / TARIC (6, 8 or 10 digits)', 'Kombinierte Nomenklatur / TARIC (6, 8 oder 10 Stellen)', 'Nomenclature combinée / TARIC (6, 8 ou 10 chiffres)'],
            'productCatalog.field.country' => ['Paese di origine (ISO)', 'Country of origin (ISO)', 'Ursprungsland (ISO)', 'Pays d\'origine (ISO)'],
            'productCatalog.field.dutyRate' => ['Dazio %', 'Customs duty %', 'Zollsatz %', 'Droit de douane %'],
            'productCatalog.field.netWeight' => ['Peso netto (kg)', 'Net weight (kg)', 'Nettogewicht (kg)', 'Poids net (kg)'],
            'productCatalog.field.grossWeight' => ['Peso lordo (kg)', 'Gross weight (kg)', 'Bruttogewicht (kg)', 'Poids brut (kg)'],
            'productCatalog.field.vatIt' => ['Codice IVA Italia', 'VAT code Italy', 'MwSt-Code Italien', 'Code TVA Italie'],
            'productCatalog.field.vatCh' => ['Codice IVA Svizzera', 'VAT code Switzerland', 'MwSt-Code Schweiz', 'Code TVA Suisse'],
            'productCatalog.field.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'productCatalog.field.unit' => ['Unità', 'Unit', 'Einheit', 'Unité'],
            'productCatalog.field.code' => ['Codice', 'Code', 'Code', 'Code'],
            'productCatalog.field.unitsPerParent' => ['Quantità del livello inferiore', 'Quantity of the lower level', 'Menge der unteren Ebene', 'Quantité du niveau inférieur'],
            'productCatalog.field.parent' => ['Livello inferiore', 'Lower level', 'Untere Ebene', 'Niveau inférieur'],
            'productCatalog.field.gtin' => ['GTIN dell\'imballo', 'Packaging GTIN', 'GTIN der Verpackung', 'GTIN de l\'emballage'],
            'productCatalog.field.rounding' => ['Arrotondamento', 'Rounding', 'Rundung', 'Arrondi'],
            'productCatalog.field.precision' => ['Decimali', 'Decimals', 'Dezimalstellen', 'Décimales'],
            'productCatalog.field.optional' => ['Facoltativo', 'Optional', 'Optional', 'Facultatif'],
            'productCatalog.field.component' => ['Componente', 'Component', 'Komponente', 'Composant'],
            'productCatalog.field.includeOptional' => ['Includi i componenti facoltativi', 'Include the optional components', 'Optionale Komponenten einschliessen', 'Inclure les composants facultatifs'],
            'productCatalog.field.skuToken' => ['Token SKU', 'SKU token', 'SKU-Token', 'Jeton SKU'],
            'productCatalog.field.priceDelta' => ['Maggiorazione prezzo', 'Price surcharge', 'Preiszuschlag', 'Supplément de prix'],
            'productCatalog.field.costDelta' => ['Maggiorazione costo', 'Cost surcharge', 'Kostenzuschlag', 'Supplément de coût'],
            'productCatalog.field.file' => ['File (.csv o .xlsx)', 'File (.csv or .xlsx)', 'Datei (.csv oder .xlsx)', 'Fichier (.csv ou .xlsx)'],
            'productCatalog.field.mode' => ['Modalità', 'Mode', 'Modus', 'Mode'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function columns(): array
    {
        return [
            'productCatalog.column.sku' => ['SKU', 'SKU', 'SKU', 'SKU'],
            'productCatalog.column.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'productCatalog.column.type' => ['Tipo', 'Type', 'Typ', 'Type'],
            'productCatalog.column.kit' => ['Kit', 'Kit', 'Set', 'Kit'],
            'productCatalog.column.variants' => ['Varianti', 'Variants', 'Varianten', 'Variantes'],
            'productCatalog.column.price' => ['Prezzo', 'Price', 'Preis', 'Prix'],
            'productCatalog.column.cost' => ['Costo', 'Cost', 'Kosten', 'Coût'],
            'productCatalog.column.active' => ['Stato', 'Status', 'Status', 'Statut'],
            'productCatalog.column.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'productCatalog.column.attributes' => ['Attributi', 'Attributes', 'Attribute', 'Attributs'],
            'productCatalog.column.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'productCatalog.column.via' => ['Dal kit', 'From the kit', 'Aus dem Set', 'Du kit'],
            'productCatalog.column.values' => ['Valori', 'Values', 'Werte', 'Valeurs'],
            'productCatalog.column.line' => ['Riga', 'Line', 'Zeile', 'Ligne'],
            'productCatalog.column.field' => ['Campo', 'Field', 'Feld', 'Champ'],
            'productCatalog.column.message' => ['Messaggio', 'Message', 'Meldung', 'Message'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'productCatalog.action.newProduct' => ['Nuovo prodotto', 'New product', 'Neues Produkt', 'Nouveau produit'],
            'productCatalog.action.newAttribute' => ['Nuovo attributo', 'New attribute', 'Neues Attribut', 'Nouvel attribut'],
            'productCatalog.action.addValue' => ['Aggiungi valore', 'Add value', 'Wert hinzufügen', 'Ajouter une valeur'],
            'productCatalog.action.addLine' => ['Aggiungi riga', 'Add line', 'Zeile hinzufügen', 'Ajouter une ligne'],
            'productCatalog.action.remove' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'productCatalog.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'productCatalog.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'productCatalog.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'productCatalog.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'productCatalog.action.confirmDelete' => ['Eliminare definitivamente?', 'Delete for good?', 'Endgültig löschen?', 'Supprimer définitivement ?'],
            'productCatalog.action.preview' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'productCatalog.action.generate' => ['Genera le varianti', 'Generate the variants', 'Varianten erzeugen', 'Générer les variantes'],
            'productCatalog.action.reprice' => ['Ricalcola prezzi e costi', 'Recalculate prices and costs', 'Preise und Kosten neu berechnen', 'Recalculer prix et coûts'],
            'productCatalog.action.saveKit' => ['Salva la distinta del kit', 'Save the kit bill of materials', 'Stückliste des Sets speichern', 'Enregistrer la nomenclature du kit'],
            'productCatalog.action.explode' => ['Esplodi il kit', 'Explode the kit', 'Set auflösen', 'Éclater le kit'],
            'productCatalog.action.saveUnits' => ['Salva le unità', 'Save the units', 'Einheiten speichern', 'Enregistrer les unités'],
            'productCatalog.action.convert' => ['Converti', 'Convert', 'Umrechnen', 'Convertir'],
            'productCatalog.action.export' => ['Esporta il catalogo', 'Export the catalog', 'Katalog exportieren', 'Exporter le catalogue'],
            'productCatalog.action.template' => ['Modello', 'Template', 'Vorlage', 'Modèle'],
            'productCatalog.action.import' => ['Importa', 'Import', 'Importieren', 'Importer'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'productCatalog.tab.general' => ['Dati generali', 'General', 'Allgemein', 'Général'],
            'productCatalog.tab.variants' => ['Varianti', 'Variants', 'Varianten', 'Variantes'],
            'productCatalog.tab.kit' => ['Distinta del kit', 'Kit bill of materials', 'Set-Stückliste', 'Nomenclature du kit'],
            'productCatalog.tab.units' => ['Unità di misura', 'Units of measure', 'Masseinheiten', 'Unités de mesure'],
            'productCatalog.section.general' => ['Dati e prezzi', 'Data and prices', 'Daten und Preise', 'Données et prix'],
            'productCatalog.section.classification' => ['Dogana e IVA (CH / IT)', 'Customs and VAT (CH / IT)', 'Zoll und MwSt (CH / IT)', 'Douane et TVA (CH / IT)'],
            'productCatalog.section.combinations' => ['Combinazioni', 'Combinations', 'Kombinationen', 'Combinaisons'],
            'productCatalog.section.preview' => ['Anteprima delle varianti', 'Variant preview', 'Variantenvorschau', 'Aperçu des variantes'],
            'productCatalog.section.variants' => ['Varianti esistenti', 'Existing variants', 'Vorhandene Varianten', 'Variantes existantes'],
            'productCatalog.section.explosion' => ['Esplosione per prelievo e DDT', 'Explosion for picking and delivery note', 'Auflösung für Kommissionierung und Lieferschein', 'Éclatement pour le prélèvement et le bon de livraison'],
            'productCatalog.section.converter' => ['Convertitore di unità', 'Unit converter', 'Einheitenumrechner', 'Convertisseur d\'unités'],
            'productCatalog.section.split' => ['Suddivisione in imballi interi', 'Split into whole packages', 'Aufteilung in ganze Verpackungen', 'Répartition en emballages entiers'],
            'productCatalog.section.errors' => ['Errori per riga', 'Errors by row', 'Fehler je Zeile', 'Erreurs par ligne'],
            'productCatalog.empty.products' => ['Nessun prodotto', 'No products', 'Keine Produkte', 'Aucun produit'],
            'productCatalog.empty.attributes' => ['Nessun attributo: creane uno per costruire una matrice di varianti', 'No attributes: create one to build a variant matrix', 'Keine Attribute: Legen Sie eines an, um eine Variantenmatrix zu erstellen', 'Aucun attribut : créez-en un pour construire une matrice de variantes'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function hints(): array
    {
        return [
            'productCatalog.hint.phantom' => ['Il kit compare su ordini e fatture; magazzino, prelievo e DDT usano i componenti.', 'The kit appears on orders and invoices; warehouse, picking and the delivery note use the components.', 'Das Set erscheint auf Aufträgen und Rechnungen; Lager, Kommissionierung und Lieferschein verwenden die Komponenten.', 'Le kit figure sur les commandes et factures ; l\'entrepôt, le prélèvement et le bon de livraison utilisent les composants.'],
            'productCatalog.hint.physical' => ['Il kit è assemblato e a magazzino con un proprio SKU e un proprio costo.', 'The kit is pre-assembled and stocked under its own SKU and cost.', 'Das Set ist vormontiert und wird mit eigener SKU und eigenen Kosten gelagert.', 'Le kit est préassemblé et stocké sous son propre SKU et son propre coût.'],
            'productCatalog.hint.notAKit' => ['Scegli il tipo di kit nei dati generali per definire una distinta.', 'Choose the kit type in the general data to define a bill of materials.', 'Wählen Sie in den Allgemeindaten die Set-Art, um eine Stückliste zu definieren.', 'Choisissez le type de kit dans les données générales pour définir une nomenclature.'],
            'productCatalog.hint.noVariants' => ['Le varianti si definiscono sul prodotto padre, non su una variante o su un kit.', 'Variants are defined on the parent product, not on a variant or a kit.', 'Varianten werden am Elternprodukt definiert, nicht an einer Variante oder einem Set.', 'Les variantes se définissent sur le produit parent, pas sur une variante ou un kit.'],
            'productCatalog.hint.notStocked' => ['Questo tipo di articolo non muove il magazzino.', 'This kind of item does not move stock.', 'Diese Artikelart bewegt keinen Lagerbestand.', 'Ce type d\'article ne mouvemente pas le stock.'],
            'productCatalog.hint.gtin' => ['Con 8, 12, 13 o 14 cifre il codice deve avere la cifra di controllo GS1 corretta.', 'With 8, 12, 13 or 14 digits the code must carry a correct GS1 check digit.', 'Bei 8, 12, 13 oder 14 Stellen muss der Code eine korrekte GS1-Prüfziffer tragen.', 'Avec 8, 12, 13 ou 14 chiffres, le code doit porter un chiffre de contrôle GS1 correct.'],
            'productCatalog.hint.tares' => ['Tariffa doganale svizzera per l\'esportazione e l\'importazione in Svizzera.', 'Swiss customs tariff for exports and imports in Switzerland.', 'Schweizer Zolltarif für Aus- und Einfuhren in der Schweiz.', 'Tarif douanier suisse pour les exportations et importations en Suisse.'],
            'productCatalog.hint.taresFormat' => ['La voce Tares ha esattamente 8 cifre.', 'A Tares number has exactly 8 digits.', 'Eine Tares-Nummer hat genau 8 Stellen.', 'Un numéro Tares compte exactement 8 chiffres.'],
            'productCatalog.hint.taric' => ['Codice UE per Intrastat e dogane italiane.', 'EU code for Intrastat and Italian customs.', 'EU-Code für Intrastat und den italienischen Zoll.', 'Code UE pour Intrastat et la douane italienne.'],
            'productCatalog.hint.taricFormat' => ['Il codice TARIC ha 6, 8 o 10 cifre.', 'A TARIC code has 6, 8 or 10 digits.', 'Ein TARIC-Code hat 6, 8 oder 10 Stellen.', 'Un code TARIC compte 6, 8 ou 10 chiffres.'],
            'productCatalog.hint.country' => ['Codice ISO a due lettere, per esempio CH, IT, DE.', 'Two-letter ISO code, for example CH, IT, DE.', 'Zweibuchstabiger ISO-Code, zum Beispiel CH, IT, DE.', 'Code ISO à deux lettres, par exemple CH, IT, DE.'],
            'productCatalog.hint.matrix' => ['Scegli i valori di ogni attributo: la matrice crea una variante per ogni combinazione, con SKU, prezzo e costo calcolati dalle maggiorazioni dei valori.', 'Choose the values of each attribute: the matrix creates one variant per combination, with the SKU, price and cost worked out from the surcharges of the values.', 'Wählen Sie die Werte jedes Attributs: Die Matrix erzeugt je Kombination eine Variante, mit SKU, Preis und Kosten aus den Zuschlägen der Werte.', 'Choisissez les valeurs de chaque attribut : la matrice crée une variante par combinaison, avec SKU, prix et coût calculés d\'après les suppléments des valeurs.'],
            'productCatalog.hint.units' => ['Ogni livello indica quante unità del livello inferiore contiene (senza livello inferiore: unità base). Le conversioni passano dall\'unità base con un solo arrotondamento.', 'Each level says how many units of the lower level it holds (with no lower level: base units). Conversions go through the base unit with a single rounding.', 'Jede Ebene gibt an, wie viele Einheiten der unteren Ebene sie enthält (ohne untere Ebene: Basiseinheiten). Umrechnungen laufen über die Basiseinheit mit nur einer Rundung.', 'Chaque niveau indique combien d\'unités du niveau inférieur il contient (sans niveau inférieur : unités de base). Les conversions passent par l\'unité de base avec un seul arrondi.'],
            'productCatalog.hint.schema' => ['Il file ha una riga di intestazione con queste colonne (in blu le obbligatorie), al massimo {max} righe.', 'The file has a header row with these columns (required ones in blue), at most {max} rows.', 'Die Datei hat eine Kopfzeile mit diesen Spalten (Pflichtspalten blau), höchstens {max} Zeilen.', 'Le fichier a une ligne d\'en-tête avec ces colonnes (obligatoires en bleu), au plus {max} lignes.'],
            'productCatalog.hint.mode.dry_run' => ['Controlla il file e mostra gli errori senza salvare nulla.', 'Checks the file and shows the errors without saving anything.', 'Prüft die Datei und zeigt die Fehler, ohne etwas zu speichern.', 'Vérifie le fichier et affiche les erreurs sans rien enregistrer.'],
            'productCatalog.hint.mode.atomic' => ['Salva tutto solo se tutte le righe sono valide.', 'Saves everything only if every row is valid.', 'Speichert alles nur, wenn jede Zeile gültig ist.', 'Enregistre tout seulement si chaque ligne est valide.'],
            'productCatalog.hint.mode.partial' => ['Salva le righe valide e segnala le altre.', 'Saves the valid rows and reports the others.', 'Speichert die gültigen Zeilen und meldet die anderen.', 'Enregistre les lignes valides et signale les autres.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'productCatalog.message.saved' => ['Salvato', 'Saved', 'Gespeichert', 'Enregistré'],
            'productCatalog.message.deleted' => ['Eliminato', 'Deleted', 'Gelöscht', 'Supprimé'],
            'productCatalog.message.repriced' => ['Prezzi e costi ricalcolati', 'Prices and costs recalculated', 'Preise und Kosten neu berechnet', 'Prix et coûts recalculés'],
            'productCatalog.message.exported' => ['Esportazione completata', 'Export completed', 'Export abgeschlossen', 'Exportation terminée'],
            'productCatalog.message.imported' => ['Importazione completata', 'Import completed', 'Import abgeschlossen', 'Importation terminée'],
            'productCatalog.message.validated' => ['File verificato: non è stato salvato nulla', 'File checked: nothing was saved', 'Datei geprüft: es wurde nichts gespeichert', 'Fichier vérifié : rien n\'a été enregistré'],
            'productCatalog.message.generated' => ['Varianti create: {created}, già presenti: {skipped}', 'Variants created: {created}, already there: {skipped}', 'Erzeugte Varianten: {created}, bereits vorhanden: {skipped}', 'Variantes créées : {created}, déjà présentes : {skipped}'],
            'productCatalog.message.importSummary' => ['Righe: {total}, create: {created}, aggiornate: {updated}, con errori: {failed}', 'Rows: {total}, created: {created}, updated: {updated}, with errors: {failed}', 'Zeilen: {total}, erstellt: {created}, aktualisiert: {updated}, mit Fehlern: {failed}', 'Lignes : {total}, créées : {created}, mises à jour : {updated}, avec erreurs : {failed}'],
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
