<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings for Mod 155 Marketing Automation (#401) across it, en, de, fr. The frontend ships no copy for this module:
 * every label, status, enumeration, problem message and compliance explanation is served from here.
 * Each tuple is [it, en, de, fr].
 */
final class MarketingAutomationFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::dashboard(),
            self::segments(),
            self::campaigns(),
            self::designer(),
            self::journey(),
            self::consent(),
            self::enumerations(),
            self::problems(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.MARKETING_AUTOMATION' => ['Marketing Automation', 'Marketing Automation', 'Marketing-Automation', 'Marketing Automation'],
            'NAV.MARKETING_DASHBOARD' => ['Dashboard ROI Marketing', 'Marketing ROI Dashboard', 'Marketing-ROI-Dashboard', 'Tableau de bord ROI marketing'],
            'NAV.MARKETING_SEGMENTS' => ['Segmenti RFM', 'RFM Segments', 'RFM-Segmente', 'Segments RFM'],
            'NAV.MARKETING_CAMPAIGNS' => ['Campagne & Journey', 'Campaigns & Journeys', 'Kampagnen & Journeys', 'Campagnes & parcours'],
            'NAV.MARKETING_DESIGNER' => ['Designer Email (MJML)', 'Email Designer (MJML)', 'E-Mail-Designer (MJML)', 'Concepteur d\'e-mails (MJML)'],
            'NAV.MARKETING_JOURNEY' => ['Journey Builder', 'Journey Builder', 'Journey-Builder', 'Constructeur de parcours'],
            'NAV.MARKETING_CONSENT' => ['Consensi & Conformità', 'Consent & Compliance', 'Einwilligungen & Compliance', 'Consentements & conformité'],
            'PAGE_TITLE.MARKETING_AUTOMATION' => ['Marketing Automation, DEM e ROI (Mod 155)', 'Marketing Automation, DEM & ROI (Mod 155)', 'Marketing-Automation, DEM & ROI (Mod 155)', 'Marketing Automation, DEM et ROI (Mod 155)'],
            'marketing.nav.aria' => ['Navigazione Marketing Automation', 'Marketing Automation navigation', 'Navigation Marketing-Automation', 'Navigation Marketing Automation'],
            'marketing.tab.dashboard' => ['Dashboard ROI', 'ROI Dashboard', 'ROI-Dashboard', 'Tableau de bord ROI'],
            'marketing.tab.segments' => ['Segmenti RFM', 'RFM Segments', 'RFM-Segmente', 'Segments RFM'],
            'marketing.tab.campaigns' => ['Campagne', 'Campaigns', 'Kampagnen', 'Campagnes'],
            'marketing.tab.designer' => ['Designer Email', 'Email Designer', 'E-Mail-Designer', 'Concepteur d\'e-mails'],
            'marketing.tab.journey' => ['Journey Builder', 'Journey Builder', 'Journey-Builder', 'Constructeur de parcours'],
            'marketing.tab.consent' => ['Consensi & Conformità', 'Consent & Compliance', 'Einwilligungen & Compliance', 'Consentements & conformité'],
            'marketing.common.yes' => ['Sì', 'Yes', 'Ja', 'Oui'],
            'marketing.common.no' => ['No', 'No', 'Nein', 'Non'],
            'marketing.action.create' => ['Crea', 'Create', 'Erstellen', 'Créer'],
            'marketing.action.update' => ['Aggiorna', 'Update', 'Aktualisieren', 'Mettre à jour'],
            'marketing.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'marketing.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'marketing.action.reset' => ['Azzera', 'Reset', 'Zurücksetzen', 'Réinitialiser'],
            'marketing.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'marketing.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'marketing.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'marketing.action.activate' => ['Attiva', 'Activate', 'Aktivieren', 'Activer'],
            'marketing.action.schedule' => ['Pianifica', 'Schedule', 'Planen', 'Planifier'],
            'marketing.action.pause' => ['Sospendi', 'Pause', 'Pausieren', 'Mettre en pause'],
            'marketing.action.archive' => ['Archivia', 'Archive', 'Archivieren', 'Archiver'],
            'marketing.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'marketing.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'marketing.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'marketing.field.channel' => ['Canale', 'Channel', 'Kanal', 'Canal'],
            'marketing.field.trigger' => ['Trigger', 'Trigger', 'Auslöser', 'Déclencheur'],
            'marketing.field.segment' => ['Segmento', 'Segment', 'Segment', 'Segment'],
            'marketing.field.provider' => ['Provider di invio', 'Delivery provider', 'Versandanbieter', 'Fournisseur d\'envoi'],
            'marketing.field.locale' => ['Lingua', 'Language', 'Sprache', 'Langue'],
            'marketing.field.min' => ['Min', 'Min', 'Min.', 'Min'],
            'marketing.field.max' => ['Max', 'Max', 'Max.', 'Max'],
            'marketing.field.utm' => ['Codice UTM campagna', 'UTM campaign code', 'UTM-Kampagnencode', 'Code UTM de campagne'],
            'marketing.field.sender_name' => ['Nome mittente', 'Sender name', 'Absendername', 'Nom de l\'expéditeur'],
            'marketing.field.sender_email' => ['Email mittente', 'Sender email', 'Absender-E-Mail', 'E-mail de l\'expéditeur'],
            'marketing.field.sender_address' => ['Indirizzo postale del mittente', 'Sender postal address', 'Postadresse des Absenders', 'Adresse postale de l\'expéditeur'],
            'marketing.field.attribution_model' => ['Modello di attribuzione', 'Attribution model', 'Attributionsmodell', 'Modèle d\'attribution'],
            'marketing.field.attribution_window' => ['Finestra di attribuzione (giorni)', 'Attribution window (days)', 'Attributionsfenster (Tage)', 'Fenêtre d\'attribution (jours)'],
            'marketing.field.coupon' => ['Codice coupon', 'Coupon code', 'Gutscheincode', 'Code promo'],
            'marketing.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'marketing.field.cost' => ['Costo campagna', 'Campaign cost', 'Kampagnenkosten', 'Coût de la campagne'],
            'marketing.attribution.first_touch' => ['First-Touch (primo contatto)', 'First-Touch', 'First-Touch (Erstkontakt)', 'First-Touch (premier contact)'],
            'marketing.attribution.last_touch' => ['Last-Touch (ultimo contatto)', 'Last-Touch', 'Last-Touch (letzter Kontakt)', 'Last-Touch (dernier contact)'],
            'marketing.roi.no_cost' => ['Nessun costo registrato', 'No cost recorded', 'Keine Kosten erfasst', 'Aucun coût enregistré'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function dashboard(): array
    {
        return [
            'marketing.dashboard.title' => ['Dashboard ROI Marketing', 'Marketing ROI Dashboard', 'Marketing-ROI-Dashboard', 'Tableau de bord ROI marketing'],
            'marketing.dashboard.subtitle' => ['Invii, interazioni, preventivi, ordini, fatture e ROI per campagna, in CHF / EUR', 'Sends, engagement, quotes, orders, invoices and ROI per campaign, in CHF / EUR', 'Versand, Interaktionen, Offerten, Aufträge, Rechnungen und ROI pro Kampagne, in CHF / EUR', 'Envois, interactions, devis, commandes, factures et ROI par campagne, en CHF / EUR'],
            'marketing.dashboard.kpis' => ['Indicatori chiave', 'Key indicators', 'Kennzahlen', 'Indicateurs clés'],
            'marketing.dashboard.campaigns' => ['Campagne', 'Campaigns', 'Kampagnen', 'Campagnes'],
            'marketing.dashboard.empty' => ['Nessuna campagna: creane una dalla scheda Campagne.', 'No campaigns yet: create one from the Campaigns tab.', 'Noch keine Kampagnen: Erstellen Sie eine im Reiter Kampagnen.', 'Aucune campagne : créez-en une depuis l\'onglet Campagnes.'],
            'marketing.kpi.sent' => ['Inviati', 'Sent', 'Versendet', 'Envoyés'],
            'marketing.kpi.delivered' => ['Consegnati', 'Delivered', 'Zugestellt', 'Délivrés'],
            'marketing.kpi.opens' => ['Aperture uniche', 'Unique opens', 'Eindeutige Öffnungen', 'Ouvertures uniques'],
            'marketing.kpi.clicks' => ['Click unici', 'Unique clicks', 'Eindeutige Klicks', 'Clics uniques'],
            'marketing.kpi.unsubscribes' => ['Disiscrizioni', 'Unsubscribes', 'Abmeldungen', 'Désinscriptions'],
            'marketing.kpi.quotes' => ['Preventivi', 'Quotes', 'Offerten', 'Devis'],
            'marketing.kpi.orders' => ['Ordini', 'Sales orders', 'Aufträge', 'Commandes'],
            'marketing.kpi.invoiced' => ['Fatturato', 'Invoiced', 'Fakturiert', 'Facturé'],
            'marketing.kpi.collected' => ['Incassato', 'Collected', 'Eingezogen', 'Encaissé'],
            'marketing.kpi.revenue' => ['Ricavi netti', 'Net revenue', 'Nettoumsatz', 'Chiffre d\'affaires net'],
            'marketing.kpi.margin' => ['Margine lordo', 'Gross margin', 'Bruttomarge', 'Marge brute'],
            'marketing.kpi.cost' => ['Costo campagna', 'Campaign cost', 'Kampagnenkosten', 'Coût de la campagne'],
            'marketing.kpi.roi' => ['ROI %', 'ROI %', 'ROI %', 'ROI %'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function segments(): array
    {
        return [
            'marketing.segments.title' => ['Segmenti RFM dinamici', 'Dynamic RFM segments', 'Dynamische RFM-Segmente', 'Segments RFM dynamiques'],
            'marketing.segments.subtitle' => ['Recency, Frequency, Monetary con esclusione automatica degli insolventi (semaforo crediti) e verifica dei consensi', 'Recency, Frequency, Monetary with automatic exclusion of insolvent customers (credit semaphore) and consent checks', 'Recency, Frequency, Monetary mit automatischem Ausschluss zahlungsunfähiger Kunden (Kredit-Ampel) und Einwilligungsprüfung', 'Récence, fréquence, montant avec exclusion automatique des clients insolvables (feu de crédit) et contrôle des consentements'],
            'marketing.segments.builder' => ['Costruttore di segmenti', 'Segment builder', 'Segment-Builder', 'Constructeur de segments'],
            'marketing.segments.preset' => ['Parti da un preset', 'Start from a preset', 'Mit einer Vorlage beginnen', 'Partir d\'un préréglage'],
            'marketing.segments.preset_placeholder' => ['Scegli un preset (Campioni, Fedeli, A Rischio...)', 'Choose a preset (Champions, Loyal, At Risk...)', 'Vorlage wählen (Champions, Treue, Abwanderungsgefährdet...)', 'Choisir un préréglage (Champions, Fidèles, À risque...)'],
            'marketing.segments.rfm_thresholds' => ['Soglie RFM', 'RFM thresholds', 'RFM-Schwellen', 'Seuils RFM'],
            'marketing.segments.recency_days' => ['Recency: giorni dall\'ultimo acquisto', 'Recency: days since last purchase', 'Recency: Tage seit dem letzten Kauf', 'Récence : jours depuis le dernier achat'],
            'marketing.segments.frequency_orders' => ['Frequency: numero di acquisti', 'Frequency: number of purchases', 'Frequency: Anzahl Käufe', 'Fréquence : nombre d\'achats'],
            'marketing.segments.monetary_value' => ['Monetary: valore acquistato', 'Monetary: purchased value', 'Monetary: Einkaufswert', 'Montant : valeur achetée'],
            'marketing.segments.business_filters' => ['Filtri commerciali', 'Business filters', 'Geschäftsfilter', 'Filtres commerciaux'],
            'marketing.segments.categories' => ['Categorie prodotto acquistate', 'Purchased product categories', 'Gekaufte Produktkategorien', 'Catégories de produits achetées'],
            'marketing.segments.skus' => ['SKU acquistati (separati da virgola)', 'Purchased SKUs (comma separated)', 'Gekaufte SKUs (durch Komma getrennt)', 'SKU achetés (séparés par des virgules)'],
            'marketing.segments.purchase_window' => ['Acquisto tra N e M mesi fa', 'Purchased between N and M months ago', 'Gekauft vor N bis M Monaten', 'Acheté il y a entre N et M mois'],
            'marketing.segments.regions' => ['Cantone o provincia (es. TI, ZH, MI)', 'Canton or province (e.g. TI, ZH, MI)', 'Kanton oder Provinz (z. B. TI, ZH, MI)', 'Canton ou province (p. ex. TI, ZH, MI)'],
            'marketing.segments.credit_semaphore' => ['Semaforo crediti (Mod 028)', 'Credit semaphore (Mod 028)', 'Kredit-Ampel (Mod 028)', 'Feu de crédit (Mod 028)'],
            'marketing.segments.exclude_insolvent' => ['Escludi clienti bloccati, insolventi o in contenzioso', 'Exclude blocked, insolvent or disputed customers', 'Gesperrte, zahlungsunfähige oder streitige Kunden ausschliessen', 'Exclure les clients bloqués, insolvables ou en litige'],
            'marketing.segments.max_overdue' => ['Scaduto massimo tollerato', 'Maximum tolerated overdue balance', 'Maximal tolerierter Ausstand', 'Impayé maximal toléré'],
            'marketing.segments.auto_refresh' => ['Aggiorna il segmento ogni ora', 'Refresh the segment every hour', 'Segment stündlich aktualisieren', 'Actualiser le segment toutes les heures'],
            'marketing.segments.preview' => ['Anteprima destinatari', 'Preview recipients', 'Empfänger-Vorschau', 'Aperçu des destinataires'],
            'marketing.segments.live_count' => ['Destinatari calcolati ora', 'Recipients computed now', 'Jetzt berechnete Empfänger', 'Destinataires calculés maintenant'],
            'marketing.segments.eligible' => ['Contatti idonei', 'Eligible contacts', 'Berechtigte Kontakte', 'Contacts éligibles'],
            'marketing.segments.excluded_insolvent' => ['Esclusi insolventi', 'Excluded insolvent', 'Ausgeschlossen (zahlungsunfähig)', 'Exclus (insolvables)'],
            'marketing.segments.excluded_consent' => ['Esclusi senza consenso', 'Excluded without consent', 'Ausgeschlossen (ohne Einwilligung)', 'Exclus (sans consentement)'],
            'marketing.segments.list' => ['Segmenti salvati', 'Saved segments', 'Gespeicherte Segmente', 'Segments enregistrés'],
            'marketing.segments.hourly' => ['Ogni ora', 'Hourly', 'Stündlich', 'Toutes les heures'],
            'marketing.segments.last_evaluated' => ['Ultimo calcolo', 'Last evaluated', 'Zuletzt berechnet', 'Dernier calcul'],
            'marketing.segments.evaluate' => ['Ricalcola', 'Recalculate', 'Neu berechnen', 'Recalculer'],
            'marketing.segments.saved' => ['Segmento salvato', 'Segment saved', 'Segment gespeichert', 'Segment enregistré'],
            'marketing.matrix.title' => ['Matrice RFM interattiva', 'Interactive RFM matrix', 'Interaktive RFM-Matrix', 'Matrice RFM interactive'],
            'marketing.matrix.labels' => ['Segmenti della matrice RFM', 'RFM matrix segments', 'Segmente der RFM-Matrix', 'Segments de la matrice RFM'],
            'marketing.matrix.heatmap' => ['Mappa di calore Recency per Frequency', 'Recency by Frequency heat map', 'Heatmap Recency nach Frequency', 'Carte thermique récence par fréquence'],
            'marketing.matrix.axes' => ['Righe: Recency (5 = più recente). Colonne: Frequency (5 = più frequente).', 'Rows: Recency (5 = most recent). Columns: Frequency (5 = most frequent).', 'Zeilen: Recency (5 = jüngste). Spalten: Frequency (5 = häufigste).', 'Lignes : récence (5 = la plus récente). Colonnes : fréquence (5 = la plus fréquente).'],
            'marketing.matrix.contacts' => ['contatti', 'contacts', 'Kontakte', 'contacts'],
            'marketing.matrix.monetary' => ['Valore', 'Value', 'Wert', 'Montant'],
            'marketing.matrix.empty' => ['Apri o calcola un segmento per vedere la matrice.', 'Open or calculate a segment to see the matrix.', 'Öffnen oder berechnen Sie ein Segment, um die Matrix zu sehen.', 'Ouvrez ou calculez un segment pour voir la matrice.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function campaigns(): array
    {
        return [
            'marketing.campaigns.title' => ['Campagne e Journey', 'Campaigns & journeys', 'Kampagnen & Journeys', 'Campagnes et parcours'],
            'marketing.campaigns.subtitle' => ['Newsletter DEM, WhatsApp, SMS e percorsi multicanale con tracking del ROI', 'Newsletter DEM, WhatsApp, SMS and multichannel journeys with ROI tracking', 'Newsletter-DEM, WhatsApp, SMS und Multichannel-Journeys mit ROI-Tracking', 'Newsletter DEM, WhatsApp, SMS et parcours multicanaux avec suivi du ROI'],
            'marketing.campaigns.new' => ['Nuova campagna', 'New campaign', 'Neue Kampagne', 'Nouvelle campagne'],
            'marketing.campaigns.edit' => ['Modifica campagna', 'Edit campaign', 'Kampagne bearbeiten', 'Modifier la campagne'],
            'marketing.campaigns.saved' => ['Campagna salvata', 'Campaign saved', 'Kampagne gespeichert', 'Campagne enregistrée'],
            'marketing.campaigns.status_changed' => ['Stato della campagna aggiornato', 'Campaign status updated', 'Kampagnenstatus aktualisiert', 'Statut de la campagne mis à jour'],
            'marketing.campaigns.insights' => ['Log e test A/B', 'Logs & A/B test', 'Protokolle & A/B-Test', 'Journaux et test A/B'],
            'marketing.campaigns.step_logs' => ['Log di esecuzione dei passi', 'Step execution log', 'Ausführungsprotokoll der Schritte', 'Journal d\'exécution des étapes'],
            'marketing.campaigns.ab_stats' => ['Risultati del test A/B', 'A/B test results', 'Ergebnisse des A/B-Tests', 'Résultats du test A/B'],
            'marketing.ab.empty' => ['Nessun invio con varianti.', 'No sends with variants yet.', 'Noch kein Versand mit Varianten.', 'Aucun envoi avec variantes pour l\'instant.'],
            'marketing.ab.criteria' => ['Criterio vincitore', 'Winner criterion', 'Gewinnerkriterium', 'Critère du gagnant'],
            'marketing.ab.winner' => ['Vincitore', 'Winner', 'Gewinner', 'Gagnant'],
            'marketing.ab.variant' => ['Variante', 'Variant', 'Variante', 'Variante'],
            'marketing.ab.open_rate' => ['Tasso di apertura', 'Open rate', 'Öffnungsrate', 'Taux d\'ouverture'],
            'marketing.ab.click_rate' => ['Tasso di click', 'Click rate', 'Klickrate', 'Taux de clic'],
            'marketing.ab.revenue' => ['Ricavi attribuiti', 'Attributed revenue', 'Zugeordneter Umsatz', 'Chiffre d\'affaires attribué'],
            'marketing.logs.when' => ['Quando', 'When', 'Wann', 'Quand'],
            'marketing.logs.step' => ['Passo', 'Step', 'Schritt', 'Étape'],
            'marketing.logs.outcome' => ['Esito', 'Outcome', 'Ergebnis', 'Résultat'],
            'marketing.logs.detail' => ['Dettaglio', 'Detail', 'Detail', 'Détail'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function designer(): array
    {
        return [
            'marketing.designer.title' => ['Designer email drag-and-drop (MJML)', 'Drag-and-drop email designer (MJML)', 'Drag-and-drop-E-Mail-Designer (MJML)', 'Concepteur d\'e-mails glisser-déposer (MJML)'],
            'marketing.designer.subtitle' => ['Blocchi, prodotti dal catalogo ERP, lingue IT/DE/FR/EN, varianti A/B e anteprima HTML', 'Blocks, products from the ERP catalog, IT/DE/FR/EN languages, A/B variants and HTML preview', 'Blöcke, Produkte aus dem ERP-Katalog, Sprachen IT/DE/FR/EN, A/B-Varianten und HTML-Vorschau', 'Blocs, produits du catalogue ERP, langues IT/DE/FR/EN, variantes A/B et aperçu HTML'],
            'marketing.designer.campaign' => ['Campagna', 'Campaign', 'Kampagne', 'Campagne'],
            'marketing.designer.start_template' => ['Parti da un modello', 'Start from a template', 'Mit einer Vorlage beginnen', 'Partir d\'un modèle'],
            'marketing.designer.locale' => ['Lingua del contenuto', 'Content language', 'Inhaltssprache', 'Langue du contenu'],
            'marketing.designer.missing_locales' => ['Testi mancanti nelle lingue', 'Missing texts in the languages', 'Fehlende Texte in den Sprachen', 'Textes manquants dans les langues'],
            'marketing.designer.palette' => ['Blocchi', 'Blocks', 'Blöcke', 'Blocs'],
            'marketing.designer.catalog_block' => ['Trascina Prodotto da Catalogo ERP', 'Drag Product from ERP Catalog', 'Produkt aus ERP-Katalog ziehen', 'Glisser un produit du catalogue ERP'],
            'marketing.designer.catalog_search' => ['Cerca prodotto o SKU', 'Search product or SKU', 'Produkt oder SKU suchen', 'Rechercher un produit ou un SKU'],
            'marketing.designer.catalog_placeholder' => ['Nome o codice prodotto', 'Product name or code', 'Produktname oder -code', 'Nom ou code du produit'],
            'marketing.designer.catalog_results' => ['Prodotti del catalogo', 'Catalog products', 'Katalogprodukte', 'Produits du catalogue'],
            'marketing.designer.import_product' => ['Importa nel blocco', 'Import into the block', 'In den Block importieren', 'Importer dans le bloc'],
            'marketing.designer.drag_hint' => ['Trascina un prodotto nell\'email: immagine, descrizione e prezzo di listino vengono importati.', 'Drag a product into the email: image, description and list price are imported.', 'Ziehen Sie ein Produkt in die E-Mail: Bild, Beschreibung und Listenpreis werden übernommen.', 'Glissez un produit dans l\'e-mail : l\'image, la description et le prix catalogue sont importés.'],
            'marketing.designer.subject' => ['Oggetto', 'Subject', 'Betreff', 'Objet'],
            'marketing.designer.preheader' => ['Pre-header', 'Pre-header', 'Preheader', 'Pré-en-tête'],
            'marketing.designer.canvas' => ['Area di composizione dell\'email', 'Email composition area', 'Kompositionsbereich der E-Mail', 'Zone de composition de l\'e-mail'],
            'marketing.designer.empty' => ['Trascina qui i blocchi o premi un blocco per aggiungerlo.', 'Drag blocks here or press a block to add it.', 'Ziehen Sie Blöcke hierher oder klicken Sie auf einen Block, um ihn hinzuzufügen.', 'Glissez des blocs ici ou appuyez sur un bloc pour l\'ajouter.'],
            'marketing.designer.move_up' => ['Sposta su', 'Move up', 'Nach oben', 'Monter'],
            'marketing.designer.move_down' => ['Sposta giù', 'Move down', 'Nach unten', 'Descendre'],
            'marketing.designer.preview' => ['Anteprima HTML', 'HTML preview', 'HTML-Vorschau', 'Aperçu HTML'],
            'marketing.designer.html_preview' => ['Anteprima email compilata', 'Compiled email preview', 'Vorschau der kompilierten E-Mail', 'Aperçu de l\'e-mail compilé'],
            'marketing.designer.saved' => ['Modello email salvato', 'Email template saved', 'E-Mail-Vorlage gespeichert', 'Modèle d\'e-mail enregistré'],
            'marketing.designer.block_settings' => ['Impostazioni del blocco', 'Block settings', 'Blockeinstellungen', 'Paramètres du bloc'],
            'marketing.designer.select_block' => ['Seleziona un blocco per modificarlo.', 'Select a block to edit it.', 'Wählen Sie einen Block zum Bearbeiten.', 'Sélectionnez un bloc pour le modifier.'],
            'marketing.designer.from_catalog' => ['Dal catalogo ERP', 'From the ERP catalog', 'Aus dem ERP-Katalog', 'Du catalogue ERP'],
            'marketing.designer.ab_test' => ['Test A/B', 'A/B test', 'A/B-Test', 'Test A/B'],
            'marketing.designer.add_variant' => ['Aggiungi variante', 'Add variant', 'Variante hinzufügen', 'Ajouter une variante'],
            'marketing.designer.remove_variants' => ['Rimuovi test A/B', 'Remove A/B test', 'A/B-Test entfernen', 'Supprimer le test A/B'],
            'marketing.designer.edit_variant' => ['Modifica oggetto', 'Edit subject', 'Betreff bearbeiten', 'Modifier l\'objet'],
            'marketing.designer.variant_percent' => ['Percentuale della variante', 'Variant percentage', 'Prozentanteil der Variante', 'Pourcentage de la variante'],
            'marketing.designer.split_total' => ['Le percentuali delle varianti devono sommare 100.', 'The variant percentages must add up to 100.', 'Die Prozentanteile der Varianten müssen 100 ergeben.', 'Les pourcentages des variantes doivent totaliser 100.'],
            'marketing.designer.no_campaign' => ['Seleziona una campagna per iniziare.', 'Select a campaign to start.', 'Wählen Sie eine Kampagne, um zu beginnen.', 'Sélectionnez une campagne pour commencer.'],
            'marketing.block.heading' => ['Titolo', 'Heading', 'Überschrift', 'Titre'],
            'marketing.block.text' => ['Testo', 'Text', 'Text', 'Texte'],
            'marketing.block.image' => ['Immagine', 'Image', 'Bild', 'Image'],
            'marketing.block.button' => ['Pulsante', 'Button', 'Schaltfläche', 'Bouton'],
            'marketing.block.divider' => ['Divisore', 'Divider', 'Trennlinie', 'Séparateur'],
            'marketing.block.spacer' => ['Spazio', 'Spacer', 'Abstand', 'Espacement'],
            'marketing.block.product' => ['Prodotto da catalogo ERP', 'ERP catalog product', 'ERP-Katalogprodukt', 'Produit du catalogue ERP'],
            'marketing.block.html' => ['HTML libero', 'Custom HTML', 'Freies HTML', 'HTML libre'],
            'marketing.prop.text' => ['Testo', 'Text', 'Text', 'Texte'],
            'marketing.prop.alt' => ['Testo alternativo', 'Alternative text', 'Alternativtext', 'Texte alternatif'],
            'marketing.prop.label' => ['Etichetta', 'Label', 'Beschriftung', 'Libellé'],
            'marketing.prop.name' => ['Nome prodotto', 'Product name', 'Produktname', 'Nom du produit'],
            'marketing.prop.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'marketing.prop.cta_label' => ['Testo del pulsante', 'Button text', 'Schaltflächentext', 'Texte du bouton'],
            'marketing.prop.src' => ['URL immagine', 'Image URL', 'Bild-URL', 'URL de l\'image'],
            'marketing.prop.href' => ['Link', 'Link', 'Link', 'Lien'],
            'marketing.prop.height' => ['Altezza', 'Height', 'Höhe', 'Hauteur'],
            'marketing.prop.image_url' => ['URL immagine prodotto', 'Product image URL', 'Produktbild-URL', 'URL de l\'image du produit'],
            'marketing.prop.url' => ['Link del prodotto', 'Product link', 'Produktlink', 'Lien du produit'],
            'marketing.prop.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'marketing.prop.html' => ['Codice HTML', 'HTML code', 'HTML-Code', 'Code HTML'],
            'marketing.prop.price' => ['Prezzo di listino', 'List price', 'Listenpreis', 'Prix catalogue'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function journey(): array
    {
        return [
            'marketing.journey.title' => ['Visual Journey Builder', 'Visual Journey Builder', 'Visueller Journey-Builder', 'Constructeur visuel de parcours'],
            'marketing.journey.subtitle' => ['Trigger, attese, condizioni su click e ordini, invii multicanale e attività per l\'agente di zona', 'Triggers, waits, click and order conditions, multichannel sends and tasks for the area agent', 'Auslöser, Wartezeiten, Klick- und Bestellbedingungen, Multichannel-Versand und Aufgaben für den Gebietsvertreter', 'Déclencheurs, attentes, conditions sur clics et commandes, envois multicanaux et tâches pour l\'agent de zone'],
            'marketing.journey.blueprint' => ['Parti da un journey predefinito', 'Start from a ready-made journey', 'Mit einem fertigen Journey beginnen', 'Partir d\'un parcours prédéfini'],
            'marketing.journey.palette' => ['Passi', 'Steps', 'Schritte', 'Étapes'],
            'marketing.journey.drag_hint' => ['Trascina un passo sull\'area di lavoro oppure premilo per aggiungerlo.', 'Drag a step onto the canvas or press it to add it.', 'Ziehen Sie einen Schritt auf die Arbeitsfläche oder klicken Sie darauf, um ihn hinzuzufügen.', 'Glissez une étape sur la zone de travail ou appuyez dessus pour l\'ajouter.'],
            'marketing.journey.entry' => ['Ingresso', 'Entry', 'Einstieg', 'Entrée'],
            'marketing.journey.set_entry' => ['Imposta come ingresso', 'Set as entry', 'Als Einstieg festlegen', 'Définir comme entrée'],
            'marketing.journey.step_settings' => ['Impostazioni del passo', 'Step settings', 'Schritteinstellungen', 'Paramètres de l\'étape'],
            'marketing.journey.select_step' => ['Seleziona un passo per configurarlo.', 'Select a step to configure it.', 'Wählen Sie einen Schritt zur Konfiguration.', 'Sélectionnez une étape pour la configurer.'],
            'marketing.journey.edge_next' => ['Passo successivo', 'Next step', 'Nächster Schritt', 'Étape suivante'],
            'marketing.journey.edge_true' => ['Se la condizione è vera', 'If the condition is true', 'Wenn die Bedingung zutrifft', 'Si la condition est vraie'],
            'marketing.journey.edge_false' => ['Se la condizione è falsa', 'If the condition is false', 'Wenn die Bedingung nicht zutrifft', 'Si la condition est fausse'],
            'marketing.journey.days' => ['Giorni', 'Days', 'Tage', 'Jours'],
            'marketing.journey.hours' => ['Ore', 'Hours', 'Stunden', 'Heures'],
            'marketing.journey.condition' => ['Condizione', 'Condition', 'Bedingung', 'Condition'],
            'marketing.journey.message' => ['Messaggio', 'Message', 'Nachricht', 'Message'],
            'marketing.journey.task_subject' => ['Oggetto dell\'attività CRM', 'CRM task subject', 'Betreff der CRM-Aufgabe', 'Objet de la tâche CRM'],
            'marketing.journey.task_type' => ['Tipo di attività', 'Task type', 'Aufgabentyp', 'Type de tâche'],
            'marketing.journey.due_in_days' => ['Scadenza (giorni)', 'Due in (days)', 'Fällig in (Tagen)', 'Échéance (jours)'],
            'marketing.journey.task_hint' => ['L\'attività è assegnata al responsabile dell\'account del cliente (Mod 031).', 'The task is assigned to the owner of the customer account (Mod 031).', 'Die Aufgabe wird dem Verantwortlichen des Kundenkontos zugewiesen (Mod 031).', 'La tâche est attribuée au responsable du compte client (Mod 031).'],
            'marketing.journey.save' => ['Salva journey', 'Save journey', 'Journey speichern', 'Enregistrer le parcours'],
            'marketing.journey.saved' => ['Journey salvato', 'Journey saved', 'Journey gespeichert', 'Parcours enregistré'],
            'marketing.journey.cannot_save' => ['Correggi gli errori prima di salvare.', 'Fix the errors before saving.', 'Beheben Sie die Fehler vor dem Speichern.', 'Corrigez les erreurs avant d\'enregistrer.'],
            'marketing.journey.issues' => ['Controlli sul journey', 'Journey checks', 'Prüfungen des Journeys', 'Contrôles du parcours'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function consent(): array
    {
        return [
            'marketing.consent.title' => ['Consensi e conformità', 'Consent & compliance', 'Einwilligungen und Compliance', 'Consentements et conformité'],
            'marketing.consent.subtitle' => ['LCSl/nLPD (Svizzera) e GDPR/Art. 130 Codice Privacy (Italia): consensi granulari, soft opt-in e registro immutabile', 'LCSl/nLPD (Switzerland) and GDPR/Art. 130 Privacy Code (Italy): granular consents, soft opt-in and an immutable audit log', 'UWG/nDSG (Schweiz) und DSGVO/Art. 130 Datenschutzkodex (Italien): granulare Einwilligungen, Soft-Opt-in und unveränderliches Protokoll', 'LCD/nLPD (Suisse) et RGPD/art. 130 Code de la vie privée (Italie) : consentements granulaires, soft opt-in et journal immuable'],
            'marketing.consent.strategies' => ['Strategie di conformità', 'Compliance strategies', 'Compliance-Strategien', 'Stratégies de conformité'],
            'marketing.consent.jurisdictions' => ['Giurisdizioni', 'Jurisdictions', 'Rechtsordnungen', 'Juridictions'],
            'marketing.consent.record' => ['Registra o modifica un consenso', 'Record or change a consent', 'Einwilligung erfassen oder ändern', 'Enregistrer ou modifier un consentement'],
            'marketing.consent.contact_id' => ['ID contatto', 'Contact ID', 'Kontakt-ID', 'ID du contact'],
            'marketing.consent.invalid_contact' => ['L\'ID contatto non è un UUID valido.', 'The contact ID is not a valid UUID.', 'Die Kontakt-ID ist keine gültige UUID.', 'L\'ID du contact n\'est pas un UUID valide.'],
            'marketing.consent.marketing' => ['Marketing diretto', 'Direct marketing', 'Direktmarketing', 'Marketing direct'],
            'marketing.consent.profiling' => ['Profilazione RFM', 'RFM profiling', 'RFM-Profiling', 'Profilage RFM'],
            'marketing.consent.soft_opt_in' => ['Soft opt-in (clienti, prodotti analoghi)', 'Soft opt-in (customers, similar products)', 'Soft-Opt-in (Kunden, ähnliche Produkte)', 'Soft opt-in (clients, produits analogues)'],
            'marketing.consent.double_opt_in' => ['Double opt-in confermato', 'Double opt-in confirmed', 'Double-Opt-in bestätigt', 'Double opt-in confirmé'],
            'marketing.consent.channels' => ['Canali consentiti', 'Allowed channels', 'Erlaubte Kanäle', 'Canaux autorisés'],
            'marketing.consent.channel_email' => ['Email', 'Email', 'E-Mail', 'E-mail'],
            'marketing.consent.channel_whatsapp' => ['WhatsApp', 'WhatsApp', 'WhatsApp', 'WhatsApp'],
            'marketing.consent.channel_sms' => ['SMS', 'SMS', 'SMS', 'SMS'],
            'marketing.consent.show_audit' => ['Mostra registro del contatto', 'Show audit log of the contact', 'Protokoll des Kontakts anzeigen', 'Afficher le journal du contact'],
            'marketing.consent.saved' => ['Consenso registrato', 'Consent recorded', 'Einwilligung erfasst', 'Consentement enregistré'],
            'marketing.consent.states' => ['Stato dei consensi', 'Consent states', 'Einwilligungsstatus', 'État des consentements'],
            'marketing.consent.unsubscribed' => ['Disiscritto il', 'Unsubscribed on', 'Abgemeldet am', 'Désinscrit le'],
            'marketing.consent.audit_log' => ['Registro di audit (immutabile)', 'Audit log (immutable)', 'Audit-Protokoll (unveränderlich)', 'Journal d\'audit (immuable)'],
            'marketing.consent.audit_hint' => ['Ogni modifica è una nuova riga con data, IP, user agent e base giuridica: non si modifica né si cancella.', 'Every change is a new row with time, IP, user agent and lawful basis: it is never edited or deleted.', 'Jede Änderung ist eine neue Zeile mit Zeit, IP, User-Agent und Rechtsgrundlage: sie wird nie bearbeitet oder gelöscht.', 'Chaque modification est une nouvelle ligne avec l\'heure, l\'IP, le user agent et la base légale : elle n\'est jamais modifiée ni supprimée.'],
            'marketing.consent.event' => ['Evento', 'Event', 'Ereignis', 'Événement'],
            'marketing.consent.lawful_basis' => ['Base giuridica', 'Lawful basis', 'Rechtsgrundlage', 'Base légale'],
            'marketing.consent.strategy' => ['Strategia', 'Strategy', 'Strategie', 'Stratégie'],
            'marketing.consent.source' => ['Fonte', 'Source', 'Quelle', 'Source'],
            'marketing.strategy.ch_lcsl_art3_nlpd' => ['Svizzera: LCSl art. 3 cpv. 1 lett. o e nLPD', 'Switzerland: UWG/LCSl Art. 3 para. 1 lit. o and nFADP', 'Schweiz: UWG Art. 3 Abs. 1 lit. o und nDSG', 'Suisse : LCD art. 3 al. 1 let. o et nLPD'],
            'marketing.strategy.ch_lcsl_art3_nlpd.summary' => ['Consenso preventivo oppure soft opt-in per i clienti esistenti su prodotti analoghi, con mittente identificabile e disiscrizione con un clic.', 'Prior consent, or soft opt-in for existing customers on similar products, with an identifiable sender and one-click unsubscribe.', 'Vorherige Einwilligung oder Soft-Opt-in für bestehende Kunden bei ähnlichen Produkten, mit erkennbarem Absender und Abmeldung mit einem Klick.', 'Consentement préalable ou soft opt-in pour les clients existants sur des produits analogues, avec expéditeur identifiable et désinscription en un clic.'],
            'marketing.strategy.ch_lcsl_art3_nlpd.rule_consent' => ['La profilazione RFM è ammessa con trasparenza; l\'opposizione è sempre rispettata.', 'RFM profiling is allowed with transparency; the objection is always honoured.', 'RFM-Profiling ist mit Transparenz zulässig; der Widerspruch wird stets respektiert.', 'Le profilage RFM est autorisé avec transparence ; l\'opposition est toujours respectée.'],
            'marketing.strategy.ch_lcsl_art3_nlpd.rule_soft' => ['Il soft opt-in vale per email e messaggi ai clienti che hanno già acquistato prodotti simili.', 'Soft opt-in covers messages to customers who already bought similar products.', 'Das Soft-Opt-in gilt für Nachrichten an Kunden, die bereits ähnliche Produkte gekauft haben.', 'Le soft opt-in couvre les messages aux clients ayant déjà acheté des produits similaires.'],
            'marketing.strategy.ch_lcsl_art3_nlpd.rule_unsubscribe' => ['Intestazioni List-Unsubscribe e List-Unsubscribe-Post (RFC 8058) in ogni email.', 'List-Unsubscribe and List-Unsubscribe-Post headers (RFC 8058) in every email.', 'List-Unsubscribe- und List-Unsubscribe-Post-Header (RFC 8058) in jeder E-Mail.', 'En-têtes List-Unsubscribe et List-Unsubscribe-Post (RFC 8058) dans chaque e-mail.'],
            'marketing.strategy.it_gdpr_art130' => ['Italia/UE: GDPR e art. 130 D.Lgs. 196/2003', 'Italy/EU: GDPR and Art. 130 D.Lgs. 196/2003', 'Italien/EU: DSGVO und Art. 130 D.Lgs. 196/2003', 'Italie/UE : RGPD et art. 130 D.Lgs. 196/2003'],
            'marketing.strategy.it_gdpr_art130.summary' => ['Consenso al marketing diretto e consenso alla profilazione sono distinti; soft spam solo via email ai clienti per prodotti analoghi.', 'Direct marketing consent and profiling consent are separate; soft spam only by email to customers for similar products.', 'Einwilligung in Direktmarketing und in Profiling sind getrennt; Soft-Spam nur per E-Mail an Kunden für ähnliche Produkte.', 'Le consentement au marketing direct et au profilage sont distincts ; soft spam uniquement par e-mail aux clients pour des produits analogues.'],
            'marketing.strategy.it_gdpr_art130.rule_consent' => ['La segmentazione RFM richiede il consenso alla profilazione.', 'RFM segmentation requires profiling consent.', 'Die RFM-Segmentierung erfordert die Einwilligung ins Profiling.', 'La segmentation RFM requiert le consentement au profilage.'],
            'marketing.strategy.it_gdpr_art130.rule_soft' => ['Art. 130 comma 4: soft spam solo per email; WhatsApp e SMS richiedono sempre il consenso.', 'Art. 130 para. 4: soft spam by email only; WhatsApp and SMS always need consent.', 'Art. 130 Abs. 4: Soft-Spam nur per E-Mail; WhatsApp und SMS erfordern stets eine Einwilligung.', 'Art. 130 al. 4 : soft spam par e-mail uniquement ; WhatsApp et SMS exigent toujours le consentement.'],
            'marketing.strategy.it_gdpr_art130.rule_unsubscribe' => ['Registro di consenso con data, IP e double opt-in; disiscrizione immediata.', 'Consent log with time, IP and double opt-in; immediate unsubscribe.', 'Einwilligungsprotokoll mit Zeit, IP und Double-Opt-in; sofortige Abmeldung.', 'Journal de consentement avec heure, IP et double opt-in ; désinscription immédiate.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'marketing.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'marketing.status.scheduled' => ['Pianificata', 'Scheduled', 'Geplant', 'Planifiée'],
            'marketing.status.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'marketing.status.paused' => ['Sospesa', 'Paused', 'Pausiert', 'En pause'],
            'marketing.status.completed' => ['Completata', 'Completed', 'Abgeschlossen', 'Terminée'],
            'marketing.status.archived' => ['Archiviata', 'Archived', 'Archiviert', 'Archivée'],
            'marketing.channel.email_dem' => ['Email DEM', 'Email DEM', 'E-Mail-DEM', 'E-mail DEM'],
            'marketing.channel.whatsapp_marketing' => ['WhatsApp Marketing', 'WhatsApp Marketing', 'WhatsApp-Marketing', 'WhatsApp Marketing'],
            'marketing.channel.sms_promo' => ['SMS promozionale', 'Promotional SMS', 'Werbe-SMS', 'SMS promotionnel'],
            'marketing.channel.multi_channel_journey' => ['Journey multicanale', 'Multichannel journey', 'Multichannel-Journey', 'Parcours multicanal'],
            'marketing.trigger.scheduled_broadcast' => ['Invio pianificato', 'Scheduled broadcast', 'Geplanter Versand', 'Envoi planifié'],
            'marketing.trigger.abandoned_cart' => ['Carrello / preventivo abbandonato', 'Abandoned cart / quote', 'Verlassener Warenkorb / Offerte', 'Panier / devis abandonné'],
            'marketing.trigger.customer_dormant_60_days' => ['Cliente dormiente da 60 giorni', 'Customer dormant for 60 days', 'Seit 60 Tagen ruhender Kunde', 'Client dormant depuis 60 jours'],
            'marketing.trigger.warranty_expiring' => ['Garanzia in scadenza', 'Warranty expiring', 'Ablaufende Garantie', 'Garantie arrivant à échéance'],
            'marketing.trigger.post_fair_lead_followup' => ['Follow-up lead post fiera', 'Post-fair lead follow-up', 'Messe-Lead-Nachfassen', 'Suivi des leads après salon'],
            'marketing.provider.smtp' => ['SMTP', 'SMTP', 'SMTP', 'SMTP'],
            'marketing.provider.ses' => ['Amazon SES', 'Amazon SES', 'Amazon SES', 'Amazon SES'],
            'marketing.provider.sendgrid' => ['SendGrid', 'SendGrid', 'SendGrid', 'SendGrid'],
            'marketing.provider.mailgun' => ['Mailgun', 'Mailgun', 'Mailgun', 'Mailgun'],
            'marketing.provider.infomaniak' => ['Infomaniak', 'Infomaniak', 'Infomaniak', 'Infomaniak'],
            'marketing.locale.it' => ['Italiano', 'Italian', 'Italienisch', 'Italien'],
            'marketing.locale.de' => ['Tedesco', 'German', 'Deutsch', 'Allemand'],
            'marketing.locale.fr' => ['Francese', 'French', 'Französisch', 'Français'],
            'marketing.locale.en' => ['Inglese', 'English', 'Englisch', 'Anglais'],
            'marketing.rfm.champions' => ['Campioni', 'Champions', 'Champions', 'Champions'],
            'marketing.rfm.loyal' => ['Fedeli', 'Loyal', 'Treue Kunden', 'Fidèles'],
            'marketing.rfm.promising' => ['Promettenti', 'Promising', 'Vielversprechend', 'Prometteurs'],
            'marketing.rfm.new_customers' => ['Nuovi clienti', 'New customers', 'Neukunden', 'Nouveaux clients'],
            'marketing.rfm.at_risk' => ['A rischio abbandono', 'At risk', 'Abwanderungsgefährdet', 'À risque'],
            'marketing.rfm.hibernating' => ['In letargo', 'Hibernating', 'Im Winterschlaf', 'En hibernation'],
            'marketing.rfm.lost' => ['Persi', 'Lost', 'Verloren', 'Perdus'],
            'marketing.step.send_email' => ['Invia email', 'Send email', 'E-Mail senden', 'Envoyer un e-mail'],
            'marketing.step.send_whatsapp' => ['Invia WhatsApp (Mod 143)', 'Send WhatsApp (Mod 143)', 'WhatsApp senden (Mod 143)', 'Envoyer WhatsApp (Mod 143)'],
            'marketing.step.send_sms' => ['Invia SMS', 'Send SMS', 'SMS senden', 'Envoyer un SMS'],
            'marketing.step.wait' => ['Attendi', 'Wait', 'Warten', 'Attendre'],
            'marketing.step.condition' => ['Condizione', 'Condition', 'Bedingung', 'Condition'],
            'marketing.step.crm_task' => ['Attività CRM per l\'agente (Mod 031)', 'CRM task for the agent (Mod 031)', 'CRM-Aufgabe für den Vertreter (Mod 031)', 'Tâche CRM pour l\'agent (Mod 031)'],
            'marketing.step.end' => ['Fine', 'End', 'Ende', 'Fin'],
            'marketing.condition.opened' => ['Ha aperto l\'email', 'Opened the email', 'Hat die E-Mail geöffnet', 'A ouvert l\'e-mail'],
            'marketing.condition.not_opened' => ['Non ha aperto l\'email', 'Did not open the email', 'Hat die E-Mail nicht geöffnet', 'N\'a pas ouvert l\'e-mail'],
            'marketing.condition.clicked' => ['Ha cliccato un link', 'Clicked a link', 'Hat auf einen Link geklickt', 'A cliqué sur un lien'],
            'marketing.condition.ordered' => ['Ha ordinato', 'Placed an order', 'Hat bestellt', 'A commandé'],
            'marketing.condition.not_ordered' => ['Non ha ordinato', 'Did not order', 'Hat nicht bestellt', 'N\'a pas commandé'],
            'marketing.condition.clicked_not_ordered' => ['Ha cliccato (listino/catalogo) e non ha ordinato', 'Clicked (price list/catalog) and did not order', 'Hat geklickt (Preisliste/Katalog) und nicht bestellt', 'A cliqué (tarif/catalogue) et n\'a pas commandé'],
            'marketing.task.call' => ['Chiamata', 'Call', 'Anruf', 'Appel'],
            'marketing.task.task' => ['Attività', 'Task', 'Aufgabe', 'Tâche'],
            'marketing.task.follow_up' => ['Follow-up', 'Follow-up', 'Nachfassen', 'Relance'],
            'marketing.outcome.queued' => ['In coda', 'Queued', 'In Warteschlange', 'En file d\'attente'],
            'marketing.outcome.waiting' => ['In attesa', 'Waiting', 'Wartet', 'En attente'],
            'marketing.outcome.skipped' => ['Saltato', 'Skipped', 'Übersprungen', 'Ignoré'],
            'marketing.outcome.true' => ['Vero', 'True', 'Wahr', 'Vrai'],
            'marketing.outcome.false' => ['Falso', 'False', 'Falsch', 'Faux'],
            'marketing.outcome.task_created' => ['Attività creata', 'Task created', 'Aufgabe erstellt', 'Tâche créée'],
            'marketing.outcome.completed' => ['Completato', 'Completed', 'Abgeschlossen', 'Terminé'],
            'marketing.reason.suppressed' => ['Indirizzo in lista di soppressione', 'Address on the suppression list', 'Adresse auf der Sperrliste', 'Adresse sur la liste de suppression'],
            'marketing.reason.sender_not_identified' => ['Mittente non identificabile', 'Sender not identifiable', 'Absender nicht identifizierbar', 'Expéditeur non identifiable'],
            'marketing.reason.unsubscribed' => ['Contatto disiscritto', 'Contact unsubscribed', 'Kontakt abgemeldet', 'Contact désinscrit'],
            'marketing.reason.channel_opt_out' => ['Canale non consentito dal contatto', 'Channel not allowed by the contact', 'Kanal vom Kontakt nicht erlaubt', 'Canal non autorisé par le contact'],
            'marketing.reason.no_consent' => ['Nessun consenso valido', 'No valid consent', 'Keine gültige Einwilligung', 'Aucun consentement valide'],
            'marketing.reason.credit_blocked' => ['Cliente bloccato dal semaforo crediti', 'Customer blocked by the credit semaphore', 'Kunde durch die Kredit-Ampel gesperrt', 'Client bloqué par le feu de crédit'],
            'marketing.reason.contact_unavailable' => ['Contatto non disponibile', 'Contact unavailable', 'Kontakt nicht verfügbar', 'Contact indisponible'],
            'marketing.reason.no_address' => ['Nessun indirizzo o numero', 'No address or number', 'Keine Adresse oder Nummer', 'Aucune adresse ni numéro'],
            'marketing.reason.assigned' => ['Assegnata all\'agente di zona', 'Assigned to the area agent', 'Dem Gebietsvertreter zugewiesen', 'Attribuée à l\'agent de zone'],
            'marketing.reason.unassigned' => ['Nessun responsabile dell\'account', 'No account owner', 'Kein Kontoverantwortlicher', 'Aucun responsable de compte'],
            'marketing.journey_issue.no_steps' => ['Il journey non ha passi', 'The journey has no steps', 'Das Journey hat keine Schritte', 'Le parcours n\'a aucune étape'],
            'marketing.journey_issue.entry_missing' => ['Serve esattamente un passo di ingresso', 'Exactly one entry step is required', 'Es wird genau ein Einstiegsschritt benötigt', 'Exactement une étape d\'entrée est requise'],
            'marketing.journey_issue.unknown_step' => ['Un collegamento punta a un passo inesistente', 'A link points to a step that does not exist', 'Eine Verbindung zeigt auf einen nicht vorhandenen Schritt', 'Un lien pointe vers une étape inexistante'],
            'marketing.journey_issue.condition_branches' => ['La condizione richiede entrambi i rami (vero e falso)', 'The condition needs both branches (true and false)', 'Die Bedingung benötigt beide Zweige (wahr und falsch)', 'La condition requiert les deux branches (vrai et faux)'],
            'marketing.journey_issue.unreachable' => ['Passo non raggiungibile dall\'ingresso', 'Step not reachable from the entry', 'Schritt vom Einstieg aus nicht erreichbar', 'Étape inaccessible depuis l\'entrée'],
            'marketing.journey_issue.dead_end' => ['Passo senza seguito: il journey termina qui', 'Step without a follower: the journey ends here', 'Schritt ohne Folgeschritt: das Journey endet hier', 'Étape sans suite : le parcours se termine ici'],
            'marketing.journey_issue.send_without_channel' => ['Passo di invio senza canale configurato', 'Send step without a configured channel', 'Sendeschritt ohne konfigurierten Kanal', 'Étape d\'envoi sans canal configuré'],
            'marketing.event.opt_in' => ['Consenso al marketing', 'Marketing opt-in', 'Marketing-Einwilligung', 'Consentement au marketing'],
            'marketing.event.double_opt_in_confirmed' => ['Double opt-in confermato', 'Double opt-in confirmed', 'Double-Opt-in bestätigt', 'Double opt-in confirmé'],
            'marketing.event.profiling_opt_in' => ['Consenso alla profilazione', 'Profiling opt-in', 'Profiling-Einwilligung', 'Consentement au profilage'],
            'marketing.event.profiling_opt_out' => ['Revoca della profilazione', 'Profiling opt-out', 'Profiling-Widerruf', 'Retrait du profilage'],
            'marketing.event.opt_out' => ['Revoca del consenso', 'Opt-out', 'Widerruf der Einwilligung', 'Retrait du consentement'],
            'marketing.event.unsubscribe' => ['Disiscrizione', 'Unsubscribe', 'Abmeldung', 'Désinscription'],
            'marketing.event.soft_opt_in_eligible' => ['Idoneo al soft opt-in', 'Soft opt-in eligible', 'Soft-Opt-in-berechtigt', 'Éligible au soft opt-in'],
            'marketing.event.hard_bounce' => ['Hard bounce', 'Hard bounce', 'Hard Bounce', 'Rebond définitif'],
            'marketing.event.spam_complaint' => ['Segnalazione spam (FBL)', 'Spam complaint (FBL)', 'Spam-Beschwerde (FBL)', 'Plainte pour spam (FBL)'],
            'marketing.event.resubscribe' => ['Nuova iscrizione', 'Resubscribe', 'Erneute Anmeldung', 'Réinscription'],
            'marketing.basis.consent' => ['Consenso', 'Consent', 'Einwilligung', 'Consentement'],
            'marketing.basis.objection' => ['Opposizione', 'Objection', 'Widerspruch', 'Opposition'],
            'marketing.basis.legitimate_interest_soft_opt_in' => ['Soft opt-in (clienti)', 'Soft opt-in (customers)', 'Soft-Opt-in (Kunden)', 'Soft opt-in (clients)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function problems(): array
    {
        return [
            'marketing.problem.generic' => ['Operazione non riuscita. Riprova o controlla i dati inseriti.', 'The operation failed. Try again or check the data entered.', 'Der Vorgang ist fehlgeschlagen. Versuchen Sie es erneut oder prüfen Sie die Eingaben.', 'L\'opération a échoué. Réessayez ou vérifiez les données saisies.'],
            'marketing.problem.invalid_transition' => ['Questo cambio di stato non è consentito per la campagna.', 'This status change is not allowed for the campaign.', 'Dieser Statuswechsel ist für die Kampagne nicht erlaubt.', 'Ce changement de statut n\'est pas autorisé pour la campagne.'],
            'marketing.problem.sender_identity_required' => ['Indica nome, email e indirizzo postale del mittente: sono obbligatori per legge.', 'Enter the sender name, email and postal address: they are required by law.', 'Geben Sie Name, E-Mail und Postadresse des Absenders an: sie sind gesetzlich vorgeschrieben.', 'Indiquez le nom, l\'e-mail et l\'adresse postale de l\'expéditeur : ils sont exigés par la loi.'],
            'marketing.problem.segment_required' => ['Scegli un segmento di destinatari per l\'invio pianificato.', 'Choose a recipient segment for the scheduled broadcast.', 'Wählen Sie ein Empfängersegment für den geplanten Versand.', 'Choisissez un segment de destinataires pour l\'envoi planifié.'],
            'marketing.problem.template_required' => ['Crea il contenuto dell\'email nel designer prima di attivare la campagna.', 'Create the email content in the designer before activating the campaign.', 'Erstellen Sie den E-Mail-Inhalt im Designer, bevor Sie die Kampagne aktivieren.', 'Créez le contenu de l\'e-mail dans le concepteur avant d\'activer la campagne.'],
            'marketing.problem.invalid_mjml' => ['Il modello MJML non è valido.', 'The MJML template is not valid.', 'Die MJML-Vorlage ist ungültig.', 'Le modèle MJML n\'est pas valide.'],
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
