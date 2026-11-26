<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings for Mod 104 Guided Data Migration & Import/Export Wizard (Issue #253).
 * Supports all 5 locales: it-CH, de-CH, fr-CH, en, it-IT (plus base locales it, de, fr).
 */
final class DataMigrationFrontendTranslations
{
    /**
     * @return array<string, array{it-CH: string, de-CH: string, fr-CH: string, en: string, it-IT: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.DATA_MIGRATION' => [
                'it-CH' => 'Migrazione Dati Guidata',
                'de-CH' => 'Geführte Datenmigration',
                'fr-CH' => 'Migration de données guidée',
                'en' => 'Guided Data Migration',
                'it-IT' => 'Migrazione Dati Guidata',
            ],
            'PAGE_TITLE.DATA_MIGRATION' => [
                'it-CH' => 'Procedura Guidata Migrazione Dati & Import/Export',
                'de-CH' => 'Assistent für Datenmigration & Import/Export',
                'fr-CH' => 'Assistant de migration de données & Import/Export',
                'en' => 'Guided Data Migration & Import/Export Wizard',
                'it-IT' => 'Procedura Guidata Migrazione Dati & Import/Export',
            ],
            'dataMigration.subtitle' => [
                'it-CH' => 'Migra anagrafiche, piani dei conti, saldi e fatture storiche da ERP italiani, svizzeri ed Excel con simulazione dry-run e rollback atomico.',
                'de-CH' => 'Migrieren Sie Stammdaten, Kontenpläne, Salden und historische Rechnungen aus Schweizer, italienischen ERPs und Excel mit Dry-Run-Simulation und atomarem Rollback.',
                'fr-CH' => 'Migrez vos tiers, plans comptables, soldes et factures historiques depuis des ERP suisses, italiens et Excel avec simulation à blanc et annulation atomique.',
                'en' => 'Migrate master data, chart of accounts, opening balances, and historical invoices from Italian, Swiss ERPs, and Excel with dry-run simulation and atomic rollback.',
                'it-IT' => 'Migra anagrafiche, piani dei conti, saldi e fatture storiche da ERP italiani, svizzeri ed Excel con simulazione dry-run e rollback atomico.',
            ],

            // Steps
            'dataMigration.step.1.title' => [
                'it-CH' => '1. ERP di origine & Entità',
                'de-CH' => '1. Quell-ERP & Entitäten',
                'fr-CH' => '1. ERP source & Entités',
                'en' => '1. Source ERP & Entities',
                'it-IT' => '1. ERP di origine & Entità',
            ],
            'dataMigration.step.2.title' => [
                'it-CH' => '2. Caricamento Archivio',
                'de-CH' => '2. Archiv-Upload',
                'fr-CH' => '2. Téléversement',
                'en' => '2. Upload Archive',
                'it-IT' => '2. Caricamento Archivio',
            ],
            'dataMigration.step.3.title' => [
                'it-CH' => '3. Mappatura Intelligente',
                'de-CH' => '3. Intelligentes Mapping',
                'fr-CH' => '3. Correspondance IA',
                'en' => '3. Smart Mapping',
                'it-IT' => '3. Mappatura Intelligente',
            ],
            'dataMigration.step.4.title' => [
                'it-CH' => '4. Dry-Run & Correzione',
                'de-CH' => '4. Dry-Run & Korrektur',
                'fr-CH' => '4. Simulation & Correction',
                'en' => '4. Dry-Run & Inline Fix',
                'it-IT' => '4. Dry-Run & Correzione',
            ],
            'dataMigration.step.5.title' => [
                'it-CH' => '5. Commit & Rollback',
                'de-CH' => '5. Commit & Rollback',
                'fr-CH' => '5. Validation & Rollback',
                'en' => '5. Commit & Rollback',
                'it-IT' => '5. Commit & Rollback',
            ],

            // Connectors
            'dataMigration.connector.FattureInCloud.name' => [
                'it-CH' => 'Fatture in Cloud',
                'de-CH' => 'Fatture in Cloud',
                'fr-CH' => 'Fatture in Cloud',
                'en' => 'Fatture in Cloud',
                'it-IT' => 'Fatture in Cloud',
            ],
            'dataMigration.connector.FattureInCloud.description' => [
                'it-CH' => 'Importa clienti, fornitori, articoli e fatture XML/CSV da Fatture in Cloud.',
                'de-CH' => 'Importiert Kunden, Lieferanten, Artikel und XML/CSV-Rechnungen aus Fatture in Cloud.',
                'fr-CH' => 'Importe clients, fournisseurs, articles et factures XML/CSV depuis Fatture in Cloud.',
                'en' => 'Import customers, vendors, products, and XML/CSV invoices from Fatture in Cloud.',
                'it-IT' => 'Importa clienti, fornitori, articoli e fatture XML/CSV da Fatture in Cloud.',
            ],
            'dataMigration.connector.DipendentiInCloud.name' => [
                'it-CH' => 'Dipendenti in Cloud',
                'de-CH' => 'Dipendenti in Cloud',
                'fr-CH' => 'Dipendenti in Cloud',
                'en' => 'Dipendenti in Cloud',
                'it-IT' => 'Dipendenti in Cloud',
            ],
            'dataMigration.connector.DipendentiInCloud.description' => [
                'it-CH' => 'Importa anagrafiche dipendenti, CF, INPS, IBAN e retribuzioni.',
                'de-CH' => 'Importiert Mitarbeiterstammdaten, CF, INPS, IBAN und Gehälter.',
                'fr-CH' => 'Importe les fiches employés, CF, INPS, IBAN et salaires.',
                'en' => 'Import employee profiles, tax codes, social security, IBAN, and salaries.',
                'it-IT' => 'Importa anagrafiche dipendenti, CF, INPS, IBAN e retribuzioni.',
            ],
            'dataMigration.connector.Bexio.name' => [
                'it-CH' => 'Bexio (Svizzera)',
                'de-CH' => 'Bexio (Schweiz)',
                'fr-CH' => 'Bexio (Suisse)',
                'en' => 'Bexio (Switzerland)',
                'it-IT' => 'Bexio (Svizzera)',
            ],
            'dataMigration.connector.Bexio.description' => [
                'it-CH' => 'Piano dei conti KMU, contatti con UID CHE, QR-IBAN e partite aperte.',
                'de-CH' => 'KMU-Kontenplan, Kontakte mit UID CHE, QR-IBAN und offene Posten.',
                'fr-CH' => 'Plan comptable PME, contacts avec IDE CHE, QR-IBAN et postes ouverts.',
                'en' => 'Swiss KMU chart of accounts, contacts with CHE UID, QR-IBAN, and open AR/AP items.',
                'it-IT' => 'Piano dei conti KMU, contatti con UID CHE, QR-IBAN e partite aperte.',
            ],
            'dataMigration.connector.Abacus.name' => [
                'it-CH' => 'Abacus ERP (Svizzera)',
                'de-CH' => 'Abacus ERP (Schweiz)',
                'fr-CH' => 'Abacus ERP (Suisse)',
                'en' => 'Abacus ERP (Switzerland)',
                'it-IT' => 'Abacus ERP (Svizzera)',
            ],
            'dataMigration.connector.Abacus.description' => [
                'it-CH' => 'Esportazioni AbaConnect / CSV: conti KMU, UID, QR-IBAN e saldi apertura.',
                'de-CH' => 'AbaConnect / CSV-Exporte: KMU-Konten, UID, QR-IBAN und Eröffnungssalden.',
                'fr-CH' => 'Exports AbaConnect / CSV : comptes PME, IDE, QR-IBAN et soldes d\'ouverture.',
                'en' => 'AbaConnect / CSV exports: Swiss KMU accounts, UID, QR-IBAN, and opening GL balances.',
                'it-IT' => 'Esportazioni AbaConnect / CSV: conti KMU, UID, QR-IBAN e saldi apertura.',
            ],
            'dataMigration.connector.BananaAccounting.name' => [
                'it-CH' => 'Banana Contabilità',
                'de-CH' => 'Banana Buchhaltung',
                'fr-CH' => 'Banana Comptabilité',
                'en' => 'Banana Accounting',
                'it-IT' => 'Banana Contabilità',
            ],
            'dataMigration.connector.BananaAccounting.description' => [
                'it-CH' => 'Piano dei conti, movimenti Dare/Avere e saldi iniziali svizzeri/italiani.',
                'de-CH' => 'Kontenplan, Soll/Haben-Buchungen und Eröffnungssalden.',
                'fr-CH' => 'Plan comptable, écritures Débit/Crédit et soldes d\'ouverture.',
                'en' => 'Chart of accounts, double-entry debit/credit transactions, and opening balances.',
                'it-IT' => 'Piano dei conti, movimenti Dare/Avere e saldi iniziali svizzeri/italiani.',
            ],
            'dataMigration.connector.DaneaEasyfatt.name' => [
                'it-CH' => 'Danea Easyfatt',
                'de-CH' => 'Danea Easyfatt',
                'fr-CH' => 'Danea Easyfatt',
                'en' => 'Danea Easyfatt',
                'it-IT' => 'Danea Easyfatt',
            ],
            'dataMigration.connector.DaneaEasyfatt.description' => [
                'it-CH' => 'Importazione anagrafiche, listini prodotti e fatture da Danea Easyfatt.',
                'de-CH' => 'Import von Stammdaten, Artikeln und Rechnungen aus Danea Easyfatt.',
                'fr-CH' => 'Importation des tiers, articles et factures depuis Danea Easyfatt.',
                'en' => 'Import customers, vendors, products, and invoices from Danea Easyfatt.',
                'it-IT' => 'Importazione anagrafiche, listini prodotti e fatture da Danea Easyfatt.',
            ],
            'dataMigration.connector.TeamSystem.name' => [
                'it-CH' => 'TeamSystem',
                'de-CH' => 'TeamSystem',
                'fr-CH' => 'TeamSystem',
                'en' => 'TeamSystem',
                'it-IT' => 'TeamSystem',
            ],
            'dataMigration.connector.TeamSystem.description' => [
                'it-CH' => 'Piano dei conti, clienti/fornitori, partite aperte e prima nota.',
                'de-CH' => 'Kontenplan, Kunden/Lieferanten, offene Posten und Buchungen.',
                'fr-CH' => 'Plan comptable, clients/fournisseurs, postes ouverts et écritures.',
                'en' => 'Chart of accounts, customers/vendors, open AR/AP items, and GL balances.',
                'it-IT' => 'Piano dei conti, clienti/fornitori, partite aperte e prima nota.',
            ],
            'dataMigration.connector.Zucchetti.name' => [
                'it-CH' => 'Zucchetti',
                'de-CH' => 'Zucchetti',
                'fr-CH' => 'Zucchetti',
                'en' => 'Zucchetti',
                'it-IT' => 'Zucchetti',
            ],
            'dataMigration.connector.Zucchetti.description' => [
                'it-CH' => 'Anagrafiche, dipendenti, contabilità e documenti storici Zucchetti.',
                'de-CH' => 'Stammdaten, Mitarbeiter, Buchhaltung und Dokumente aus Zucchetti.',
                'fr-CH' => 'Tiers, employés, comptabilité et documents historiques Zucchetti.',
                'en' => 'Master data, employees, accounting, and historical documents from Zucchetti.',
                'it-IT' => 'Anagrafiche, dipendenti, contabilità e documenti storici Zucchetti.',
            ],
            'dataMigration.connector.Odoo.name' => [
                'it-CH' => 'Odoo ERP',
                'de-CH' => 'Odoo ERP',
                'fr-CH' => 'Odoo ERP',
                'en' => 'Odoo ERP',
                'it-IT' => 'Odoo ERP',
            ],
            'dataMigration.connector.Odoo.description' => [
                'it-CH' => 'Importa res.partner, product.template, account.account e fatture Odoo.',
                'de-CH' => 'Importiert res.partner, product.template, account.account und Odoo-Rechnungen.',
                'fr-CH' => 'Importe res.partner, product.template, account.account et factures Odoo.',
                'en' => 'Import res.partner, product.template, account.account, and invoices from Odoo.',
                'it-IT' => 'Importa res.partner, product.template, account.account e fatture Odoo.',
            ],
            'dataMigration.connector.FatturaPaXmlZipArchive.name' => [
                'it-CH' => 'Archivio ZIP FatturaPA / SDI',
                'de-CH' => 'FatturaPA / SDI ZIP-Archiv',
                'fr-CH' => 'Archive ZIP FatturaPA / SDI',
                'en' => 'FatturaPA / SDI XML ZIP Archive',
                'it-IT' => 'Archivio ZIP FatturaPA / SDI',
            ],
            'dataMigration.connector.FatturaPaXmlZipArchive.description' => [
                'it-CH' => 'Importazione massiva multi-anno da Cassetto Fiscale AdE con deduplica automatica.',
                'de-CH' => 'Mehrjähriger Massenimport von FatturaPA-XML-Dateien mit Deduplizierung.',
                'fr-CH' => 'Importation en masse multi-années de fichiers XML FatturaPA avec déduplication.',
                'en' => 'Multi-year bulk import of Italian FatturaPA/SDI XML archives with automatic deduplication.',
                'it-IT' => 'Importazione massiva multi-anno da Cassetto Fiscale AdE con deduplica automatica.',
            ],
            'dataMigration.connector.GenericCsvXlsx.name' => [
                'it-CH' => 'Excel / CSV Generico',
                'de-CH' => 'Generisches Excel / CSV',
                'fr-CH' => 'Excel / CSV Générique',
                'en' => 'Generic Excel / CSV',
                'it-IT' => 'Excel / CSV Generico',
            ],
            'dataMigration.connector.GenericCsvXlsx.description' => [
                'it-CH' => 'Modelli standard .xlsx e .csv per qualsiasi entità ERP.',
                'de-CH' => 'Standard-.xlsx- und .csv-Vorlagen für beliebige ERP-Entitäten.',
                'fr-CH' => 'Modèles standard .xlsx et .csv pour toute entité ERP.',
                'en' => 'Standard .xlsx and .csv templates for any ERP entity.',
                'it-IT' => 'Modelli standard .xlsx e .csv per qualsiasi entità ERP.',
            ],

            // Target Entities
            'dataMigration.entity.ChartOfAccounts' => [
                'it-CH' => 'Piano dei Conti',
                'de-CH' => 'Kontenplan',
                'fr-CH' => 'Plan comptable',
                'en' => 'Chart of Accounts',
                'it-IT' => 'Piano dei Conti',
            ],
            'dataMigration.entity.Customers' => [
                'it-CH' => 'Clienti',
                'de-CH' => 'Kunden',
                'fr-CH' => 'Clients',
                'en' => 'Customers',
                'it-IT' => 'Clienti',
            ],
            'dataMigration.entity.Vendors' => [
                'it-CH' => 'Fornitori',
                'de-CH' => 'Lieferanten',
                'fr-CH' => 'Fournisseurs',
                'en' => 'Vendors',
                'it-IT' => 'Fornitori',
            ],
            'dataMigration.entity.Products' => [
                'it-CH' => 'Prodotti e Servizi',
                'de-CH' => 'Produkte & Dienstleistungen',
                'fr-CH' => 'Produits & Services',
                'en' => 'Products & Catalog',
                'it-IT' => 'Prodotti e Servizi',
            ],
            'dataMigration.entity.Employees' => [
                'it-CH' => 'Dipendenti',
                'de-CH' => 'Mitarbeitende',
                'fr-CH' => 'Employés',
                'en' => 'Employees',
                'it-IT' => 'Dipendenti',
            ],
            'dataMigration.entity.OpeningGlBalances' => [
                'it-CH' => 'Saldi Apertura Contabilità',
                'de-CH' => 'Eröffnungssalden Hauptbuch',
                'fr-CH' => 'Soldes d\'ouverture GL',
                'en' => 'Opening GL Balances',
                'it-IT' => 'Saldi Apertura Contabilità',
            ],
            'dataMigration.entity.OpenArApItems' => [
                'it-CH' => 'Partite Aperte Clienti/Fornitori',
                'de-CH' => 'Offene Posten (Debitoren/Kreditoren)',
                'fr-CH' => 'Postes ouverts Clients/Fournisseurs',
                'en' => 'Open AR/AP Items',
                'it-IT' => 'Partite Aperte Clienti/Fornitori',
            ],
            'dataMigration.entity.HistoricalInvoices' => [
                'it-CH' => 'Fatture Storiche (Attive/Passive)',
                'de-CH' => 'Historische Rechnungen',
                'fr-CH' => 'Factures historiques',
                'en' => 'Historical Invoices',
                'it-IT' => 'Fatture Storiche (Attive/Passive)',
            ],

            // Status Badges
            'dataMigration.status.Uploaded' => [
                'it-CH' => 'Caricato',
                'de-CH' => 'Hochgeladen',
                'fr-CH' => 'Téléversé',
                'en' => 'Uploaded',
                'it-IT' => 'Caricato',
            ],
            'dataMigration.status.Mapped' => [
                'it-CH' => 'Mappato',
                'de-CH' => 'Zugeordnet',
                'fr-CH' => 'Mappé',
                'en' => 'Mapped',
                'it-IT' => 'Mappato',
            ],
            'dataMigration.status.DryRunValidated' => [
                'it-CH' => 'Dry-Run Superato',
                'de-CH' => 'Dry-Run Bestanden',
                'fr-CH' => 'Simulation Validée',
                'en' => 'Dry-Run Validated',
                'it-IT' => 'Dry-Run Superato',
            ],
            'dataMigration.status.Executing' => [
                'it-CH' => 'In Esecuzione',
                'de-CH' => 'Wird ausgeführt',
                'fr-CH' => 'En cours',
                'en' => 'Executing',
                'it-IT' => 'In Esecuzione',
            ],
            'dataMigration.status.Completed' => [
                'it-CH' => 'Completato',
                'de-CH' => 'Abgeschlossen',
                'fr-CH' => 'Terminé',
                'en' => 'Completed',
                'it-IT' => 'Completato',
            ],
            'dataMigration.status.RolledBack' => [
                'it-CH' => 'Annullato (Rollback)',
                'de-CH' => 'Zurückgesetzt (Rollback)',
                'fr-CH' => 'Annulé (Rollback)',
                'en' => 'Rolled Back',
                'it-IT' => 'Annullato (Rollback)',
            ],
            'dataMigration.status.Failed' => [
                'it-CH' => 'Fallito',
                'de-CH' => 'Fehlgeschlagen',
                'fr-CH' => 'Échoué',
                'en' => 'Failed',
                'it-IT' => 'Fallito',
            ],

            // Actions & Labels
            'dataMigration.action.next' => ['it-CH' => 'Avanti', 'de-CH' => 'Weiter', 'fr-CH' => 'Suivant', 'en' => 'Next', 'it-IT' => 'Avanti'],
            'dataMigration.action.back' => ['it-CH' => 'Indietro', 'de-CH' => 'Zurück', 'fr-CH' => 'Retour', 'en' => 'Back', 'it-IT' => 'Indietro'],
            'dataMigration.action.downloadXlsx' => ['it-CH' => 'Scarica Modello XLSX', 'de-CH' => 'XLSX-Vorlage laden', 'fr-CH' => 'Télécharger XLSX', 'en' => 'Download XLSX Template', 'it-IT' => 'Scarica Modello XLSX'],
            'dataMigration.action.downloadCsv' => ['it-CH' => 'Scarica Modello CSV', 'de-CH' => 'CSV-Vorlage laden', 'fr-CH' => 'Télécharger CSV', 'en' => 'Download CSV Template', 'it-IT' => 'Scarica Modello CSV'],
            'dataMigration.action.uploadFile' => ['it-CH' => 'Carica Archivio e Analizza', 'de-CH' => 'Datei hochladen & analysieren', 'fr-CH' => 'Téléverser et analyser', 'en' => 'Upload & Parse Archive', 'it-IT' => 'Carica Archivio e Analizza'],
            'dataMigration.action.autoMap' => ['it-CH' => 'Rilevamento Automatico Colonne', 'de-CH' => 'Spalten automatisch zuordnen', 'fr-CH' => 'Détection automatique', 'en' => 'Auto-Detect Mapping', 'it-IT' => 'Rilevamento Automatico Colonne'],
            'dataMigration.action.saveTemplate' => ['it-CH' => 'Salva come Modello', 'de-CH' => 'Als Vorlage speichern', 'fr-CH' => 'Enregistrer le modèle', 'en' => 'Save as Template', 'it-IT' => 'Salva come Modello'],
            'dataMigration.action.runDryRun' => ['it-CH' => 'Esegui Simulazione Dry-Run', 'de-CH' => 'Dry-Run-Simulation starten', 'fr-CH' => 'Lancer la simulation à blanc', 'en' => 'Run Dry-Run Simulation', 'it-IT' => 'Esegui Simulazione Dry-Run'],
            'dataMigration.action.applySuggestedFix' => ['it-CH' => 'Applica Correzione', 'de-CH' => 'Vorschlag übernehmen', 'fr-CH' => 'Appliquer la correction', 'en' => 'Apply Suggested Fix', 'it-IT' => 'Applica Correzione'],
            'dataMigration.action.saveInlineFix' => ['it-CH' => 'Salva Riga', 'de-CH' => 'Zeile speichern', 'fr-CH' => 'Enregistrer', 'en' => 'Save Row', 'it-IT' => 'Salva Riga'],
            'dataMigration.action.commit' => ['it-CH' => 'Esegui Commit Definitivo', 'de-CH' => 'Migration endgültig ausführen', 'fr-CH' => 'Valider la migration', 'en' => 'Execute Atomic Commit', 'it-IT' => 'Esegui Commit Definitivo'],
            'dataMigration.action.rollback' => ['it-CH' => 'Rollback 1-Click', 'de-CH' => '1-Klick-Rollback', 'fr-CH' => 'Annulation 1-clic', 'en' => '1-Click Rollback', 'it-IT' => 'Rollback 1-Click'],

            // Labels & Headers
            'dataMigration.label.selectConnector' => ['it-CH' => 'Seleziona il sistema di origine', 'de-CH' => 'Quellsystem auswählen', 'fr-CH' => 'Sélectionner le système source', 'en' => 'Select Source ERP System', 'it-IT' => 'Seleziona il sistema di origine'],
            'dataMigration.label.selectEntities' => ['it-CH' => 'Entità da migrare', 'de-CH' => 'Zu migrierende Entitäten', 'fr-CH' => 'Entités à migrer', 'en' => 'Target Entities to Migrate', 'it-IT' => 'Entità da migrare'],
            'dataMigration.label.mappingTemplate' => ['it-CH' => 'Modello di mappatura (opzionale)', 'de-CH' => 'Zuordnungsvorlage (optional)', 'fr-CH' => 'Modèle de correspondance (optionnel)', 'en' => 'Mapping Template (Optional)', 'it-IT' => 'Modello di mappatura (opzionale)'],
            'dataMigration.label.dropzoneTitle' => ['it-CH' => 'Trascina qui il file .zip, .xlsx, .csv o .xml oppure clicca per selezionare', 'de-CH' => 'Ziehen Sie eine .zip-, .xlsx-, .csv- oder .xml-Datei hierher oder klicken Sie', 'fr-CH' => 'Glissez un fichier .zip, .xlsx, .csv ou .xml ici ou cliquez pour choisir', 'en' => 'Drag & drop .zip, .xlsx, .csv, or .xml file here or click to browse', 'it-IT' => 'Trascina qui il file .zip, .xlsx, .csv o .xml oppure clicca per selezionare'],
            'dataMigration.label.targetField' => ['it-CH' => 'Campo ERP', 'de-CH' => 'ERP-Feld', 'fr-CH' => 'Champ ERP', 'en' => 'Target Field', 'it-IT' => 'Campo ERP'],
            'dataMigration.label.sourceColumn' => ['it-CH' => 'Colonna Sorgente', 'de-CH' => 'Quellspalte', 'fr-CH' => 'Colonne source', 'en' => 'Source Column', 'it-IT' => 'Colonna Sorgente'],
            'dataMigration.label.confidence' => ['it-CH' => 'Affidabilità', 'de-CH' => 'Konfidenz', 'fr-CH' => 'Confiance', 'en' => 'Confidence', 'it-IT' => 'Affidabilità'],
            'dataMigration.label.defaultValue' => ['it-CH' => 'Valore predefinito', 'de-CH' => 'Standardwert', 'fr-CH' => 'Valeur par défaut', 'en' => 'Default Value', 'it-IT' => 'Valore predefinito'],
            'dataMigration.label.dateFormat' => ['it-CH' => 'Formato Data', 'de-CH' => 'Datumsformat', 'fr-CH' => 'Format de date', 'en' => 'Date Format', 'it-IT' => 'Formato Data'],
            'dataMigration.label.decimalSeparator' => ['it-CH' => 'Separatore Decimali', 'de-CH' => 'Dezimaltrennzeichen', 'fr-CH' => 'Séparateur décimal', 'en' => 'Decimal Separator', 'it-IT' => 'Separatore Decimali'],
            'dataMigration.label.currencyDefault' => ['it-CH' => 'Valuta Predefinita', 'de-CH' => 'Standardwährung', 'fr-CH' => 'Devise par défaut', 'en' => 'Default Currency', 'it-IT' => 'Valuta Predefinita'],
            'dataMigration.label.totalRows' => ['it-CH' => 'Righe Totali', 'de-CH' => 'Zeilen Gesamt', 'fr-CH' => 'Total lignes', 'en' => 'Total Rows', 'it-IT' => 'Righe Totali'],
            'dataMigration.label.validRows' => ['it-CH' => 'Righe Valide', 'de-CH' => 'Gültige Zeilen', 'fr-CH' => 'Lignes valides', 'en' => 'Valid Rows', 'it-IT' => 'Righe Valide'],
            'dataMigration.label.errorRows' => ['it-CH' => 'Righe con Errori', 'de-CH' => 'Fehlerhafte Zeilen', 'fr-CH' => 'Lignes en erreur', 'en' => 'Error Rows', 'it-IT' => 'Righe con Errori'],
            'dataMigration.label.glBalance' => ['it-CH' => 'Quadratura Dare / Avere', 'de-CH' => 'Soll / Haben Ausgleich', 'fr-CH' => 'Équilibre Débit / Crédit', 'en' => 'Debit / Credit Balance', 'it-IT' => 'Quadratura Dare / Avere'],
            'dataMigration.label.balanced' => ['it-CH' => 'Quadrato', 'de-CH' => 'Ausgeglichen', 'fr-CH' => 'Équilibré', 'en' => 'Balanced', 'it-IT' => 'Quadrato'],
            'dataMigration.label.unbalanced' => ['it-CH' => 'Sbilanciato', 'de-CH' => 'Unausgeglichen', 'fr-CH' => 'Déséquilibré', 'en' => 'Unbalanced', 'it-IT' => 'Sbilanciato'],
            'dataMigration.label.historyTitle' => ['it-CH' => 'Storico Migrazioni e Rollback', 'de-CH' => 'Migrationsverlauf & Rollback', 'fr-CH' => 'Historique des migrations & Rollback', 'en' => 'Migration History & Rollback', 'it-IT' => 'Storico Migrazioni e Rollback'],
            'dataMigration.label.rowIndex' => ['it-CH' => 'Riga', 'de-CH' => 'Zeile', 'fr-CH' => 'Ligne', 'en' => 'Row', 'it-IT' => 'Riga'],
            'dataMigration.label.recordId' => ['it-CH' => 'Identificativo', 'de-CH' => 'Datensatz-ID', 'fr-CH' => 'Identifiant', 'en' => 'Record ID', 'it-IT' => 'Identificativo'],
            'dataMigration.label.rawValue' => ['it-CH' => 'Valore Attuale', 'de-CH' => 'Aktueller Wert', 'fr-CH' => 'Valeur actuelle', 'en' => 'Current Value', 'it-IT' => 'Valore Attuale'],
            'dataMigration.label.suggestedFix' => ['it-CH' => 'Correzione Suggerita', 'de-CH' => 'Vorgeschlagene Korrektur', 'fr-CH' => 'Correction suggérée', 'en' => 'Suggested Fix', 'it-IT' => 'Correzione Suggerita'],
            'dataMigration.label.confirmRollbackTitle' => ['it-CH' => 'Confermare il Rollback Atomico?', 'de-CH' => 'Atomaren Rollback bestätigen?', 'fr-CH' => 'Confirmer l\'annulation atomique ?', 'en' => 'Confirm Atomic Rollback?', 'it-IT' => 'Confermare il Rollback Atomico?'],
            'dataMigration.label.confirmRollbackBody' => ['it-CH' => 'Tutti i record creati da questa migrazione verranno eliminati in modo atomico.', 'de-CH' => 'Alle durch diese Migration erstellten Datensätze werden atomar entfernt.', 'fr-CH' => 'Tous les enregistrements créés par cette migration seront supprimés atomiquement.', 'en' => 'All records created by this migration batch will be atomically reverted.', 'it-IT' => 'Tutti i record creati da questa migrazione verranno eliminati in modo atomico.'],

            // Validation Error Codes
            'dataMigration.error.MISSING_REQUIRED_FIELD' => [
                'it-CH' => 'Campo obbligatorio mancante.',
                'de-CH' => 'Pflichtfeld fehlt.',
                'fr-CH' => 'Champ obligatoire manquant.',
                'en' => 'Required field is missing.',
                'it-IT' => 'Campo obbligatorio mancante.',
            ],
            'dataMigration.error.INVALID_ITALIAN_VAT' => [
                'it-CH' => 'Partita IVA italiana non valida (cifra di controllo errata).',
                'de-CH' => 'Ungültige italienische MwSt-Nummer (Prüfziffer falsch).',
                'fr-CH' => 'Numéro de TVA italien invalide (chiffre de contrôle incorrect).',
                'en' => 'Invalid Italian Partita IVA (check digit mismatch).',
                'it-IT' => 'Partita IVA italiana non valida (cifra di controllo errata).',
            ],
            'dataMigration.error.INVALID_ITALIAN_FISCAL_CODE' => [
                'it-CH' => 'Codice Fiscale italiano non valido.',
                'de-CH' => 'Ungültiger italienischer Steuercode (Codice Fiscale).',
                'fr-CH' => 'Code fiscal italien invalide.',
                'en' => 'Invalid Italian Codice Fiscale.',
                'it-IT' => 'Codice Fiscale italiano non valido.',
            ],
            'dataMigration.error.INVALID_SWISS_UID' => [
                'it-CH' => 'UID/IDI svizzero non valido (formato atteso CHE-xxx.xxx.xxx).',
                'de-CH' => 'Ungültige Schweizer UID (Format CHE-xxx.xxx.xxx erwartet).',
                'fr-CH' => 'IDE suisse invalide (format attendu CHE-xxx.xxx.xxx).',
                'en' => 'Invalid Swiss UID (expected CHE-xxx.xxx.xxx).',
                'it-IT' => 'UID/IDI svizzero non valido (formato atteso CHE-xxx.xxx.xxx).',
            ],
            'dataMigration.error.INVALID_IBAN_CHECKSUM' => [
                'it-CH' => 'IBAN non valido (checksum MOD-97 errato).',
                'de-CH' => 'Ungültige IBAN (MOD-97 Prüfsumme falsch).',
                'fr-CH' => 'IBAN invalide (somme de contrôle MOD-97 incorrecte).',
                'en' => 'Invalid IBAN checksum (MOD-97 failed).',
                'it-IT' => 'IBAN non valido (checksum MOD-97 errato).',
            ],
            'dataMigration.error.INVALID_SWISS_QR_IBAN' => [
                'it-CH' => 'QR-IBAN svizzero non valido (IID deve essere 30000-31999).',
                'de-CH' => 'Ungültige Schweizer QR-IBAN (IID muss 30000-31999 sein).',
                'fr-CH' => 'QR-IBAN suisse invalide (IID doit être 30000-31999).',
                'en' => 'Invalid Swiss QR-IBAN (IID must be 30000-31999).',
                'it-IT' => 'QR-IBAN svizzero non valido (IID deve essere 30000-31999).',
            ],
            'dataMigration.error.UNKNOWN_ACCOUNT_CODE' => [
                'it-CH' => 'Codice conto inesistente nel Piano dei Conti.',
                'de-CH' => 'Kontonummer im Kontenplan nicht gefunden.',
                'fr-CH' => 'Numéro de compte introuvable dans le plan comptable.',
                'en' => 'Account code not found in Chart of Accounts.',
                'it-IT' => 'Codice conto inesistente nel Piano dei Conti.',
            ],
            'dataMigration.error.UNKNOWN_CURRENCY_CODE' => [
                'it-CH' => 'Codice valuta ISO non riconosciuto.',
                'de-CH' => 'Unbekannter ISO-Währungscode.',
                'fr-CH' => 'Code devise ISO inconnu.',
                'en' => 'Unknown ISO currency code.',
                'it-IT' => 'Codice valuta ISO non riconosciuto.',
            ],
            'dataMigration.error.DUPLICATE_RECORD_KEY' => [
                'it-CH' => 'Chiave duplicata rilevata nel lotto o in anagrafica.',
                'de-CH' => 'Doppelter Schlüssel im Batch oder Bestand erkannt.',
                'fr-CH' => 'Clé en double détectée dans le lot ou la base.',
                'en' => 'Duplicate record key detected.',
                'it-IT' => 'Chiave duplicata rilevata nel lotto o in anagrafica.',
            ],
            'dataMigration.error.UNBALANCED_OPENING_GL_BALANCES' => [
                'it-CH' => 'I saldi di apertura Dare e Avere non quadrano (Dare != Avere).',
                'de-CH' => 'Eröffnungssalden Soll und Haben sind nicht ausgeglichen (Soll != Haben).',
                'fr-CH' => 'Les soldes d\'ouverture Débit et Crédit ne sont pas équilibrés.',
                'en' => 'Opening GL balances are unbalanced (Debit != Credit).',
                'it-IT' => 'I saldi di apertura Dare e Avere non quadrano (Dare != Avere).',
            ],
            'dataMigration.error.OPEN_AMOUNT_EXCEEDS_ORIGINAL' => [
                'it-CH' => 'L\'importo aperto supera l\'importo originale del documento.',
                'de-CH' => 'Offener Betrag übersteigt den Ursprungsbetrag.',
                'fr-CH' => 'Le montant ouvert dépasse le montant initial.',
                'en' => 'Open amount exceeds original document amount.',
                'it-IT' => 'L\'importo aperto supera l\'importo originale del documento.',
            ],
            'dataMigration.error.INVOICE_TOTAL_MISMATCH' => [
                'it-CH' => 'Totale lordo fattura non coerente con imponibile + IVA.',
                'de-CH' => 'Bruttobetrag stimmt nicht mit Netto + MwSt überein.',
                'fr-CH' => 'Le total TTC ne correspond pas au HT + TVA.',
                'en' => 'Invoice gross total does not match net + VAT.',
                'it-IT' => 'Totale lordo fattura non coerente con imponibile + IVA.',
            ],
        ];
    }

    /**
     * Supports all 5 requested locales (it-CH, de-CH, fr-CH, en, it-IT) as well as base locales (it, de, fr).
     *
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $resolvedKey = match ($locale) {
            'it', 'it-CH' => 'it-CH',
            'it-IT' => 'it-IT',
            'de', 'de-CH' => 'de-CH',
            'fr', 'fr-CH' => 'fr-CH',
            'en' => 'en',
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        $resolved = [];
        foreach (self::definitions() as $key => $map) {
            $resolved[$key] = $map[$resolvedKey];
        }

        return $resolved;
    }
}
