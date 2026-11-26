<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings for Mod 032 External Accountant & Fiduciary Portal (Issue #336).
 * Supports all 5 locales: it-CH, de-CH, fr-CH, en, it-IT (plus base locales it, de, fr).
 */
final class FiduciaryPortalFrontendTranslations
{
    /**
     * @return array<string, array{it-CH: string, de-CH: string, fr-CH: string, en: string, it-IT: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.FIDUCIARY_PORTAL' => [
                'it-CH' => 'Portale Fiduciaria & Commercialista',
                'de-CH' => 'Treuhand- & Steuerberaterportal',
                'fr-CH' => 'Portail Fiduciaire & Expert-Comptable',
                'en' => 'Fiduciary & Accountant Portal',
                'it-IT' => 'Portale Commercialista & Revisore',
            ],
            'PAGE_TITLE.FIDUCIARY_PORTAL' => [
                'it-CH' => 'Area di Lavoro Fiduciario Esterno & Commercialista',
                'de-CH' => 'Arbeitsbereich Externer Treuhänder & Revisoren',
                'fr-CH' => 'Espace de Travail Fiduciaire & Réviseur Externe',
                'en' => 'External Accountant & Fiduciary Workspace',
                'it-IT' => 'Area di Lavoro Commercialista & Revisore Esterno',
            ],
            'fiduciaryPortal.subtitle' => [
                'it-CH' => 'Revisione multi-azienda, verifica scritture, blocco periodi, transcodifica piano dei conti, esportazioni Abacus/DATEV/TeamSystem/Zucchetti e fascicolo bilancio.',
                'de-CH' => 'Mandantenübergreifende Revision, Belegprüfung, Periodensperre, Kontenzuordnung, Abacus/DATEV/TeamSystem/Zucchetti-Exporte und Bilanzdossier.',
                'fr-CH' => 'Révision multi-mandats, vérification des écritures, verrouillage des périodes, transcodage comptable, exports Abacus/DATEV/TeamSystem/Zucchetti et dossier de bilan.',
                'en' => 'Multi-client external audit, fiduciary verification, period locking, chart-of-accounts transcoding, Abacus/DATEV/TeamSystem/Zucchetti exports, and digital balance sheet dossier.',
                'it-IT' => 'Revisione multi-azienda, visto del commercialista, blocco periodi, mappatura piano dei conti, esportazioni DATEV/TeamSystem/Zucchetti/Abacus e fascicolo di bilancio.',
            ],

            // Multi-tenant client switcher & overview
            'fiduciaryPortal.clientSwitcher.label' => [
                'it-CH' => 'Azienda Cliente Attiva',
                'de-CH' => 'Aktiver Mandant',
                'fr-CH' => 'Mandat Client Actif',
                'en' => 'Active Client Company',
                'it-IT' => 'Azienda Cliente Attiva',
            ],
            'fiduciaryPortal.clientSwitcher.switchAction' => [
                'it-CH' => 'Seleziona Cliente',
                'de-CH' => 'Mandant wechseln',
                'fr-CH' => 'Sélectionner le mandat',
                'en' => 'Switch Client',
                'it-IT' => 'Seleziona Azienda',
            ],
            'fiduciaryPortal.overview.title' => [
                'it-CH' => 'Panoramica Mandati Multi-Azienda',
                'de-CH' => 'Mandantenübersicht',
                'fr-CH' => 'Vue d\'ensemble Multi-Mandats',
                'en' => 'Multi-Client Overview',
                'it-IT' => 'Panoramica Aziende Clienti',
            ],
            'fiduciaryPortal.kpi.verificationProgress' => [
                'it-CH' => 'Avanzamento Verifica',
                'de-CH' => 'Prüffortschritt',
                'fr-CH' => 'Progression Vérification',
                'en' => 'Verification Progress',
                'it-IT' => 'Avanzamento Visto Revisore',
            ],
            'fiduciaryPortal.kpi.lockedPeriods' => [
                'it-CH' => 'Periodi Bloccati',
                'de-CH' => 'Gesperrte Perioden',
                'fr-CH' => 'Périodes Verrouillées',
                'en' => 'Locked Periods',
                'it-IT' => 'Periodi Bloccati',
            ],
            'fiduciaryPortal.kpi.openNotes' => [
                'it-CH' => 'Note Aperte',
                'de-CH' => 'Offene Notizen',
                'fr-CH' => 'Notes Ouvertes',
                'en' => 'Open Annotations',
                'it-IT' => 'Note Aperte',
            ],
            'fiduciaryPortal.kpi.unmappedAccounts' => [
                'it-CH' => 'Conti Non Mappati',
                'de-CH' => 'Nicht zugeordnete Konten',
                'fr-CH' => 'Comptes Non Mappés',
                'en' => 'Unmapped Accounts',
                'it-IT' => 'Conti Non Mappati',
            ],
            'fiduciaryPortal.kpi.exportRuns' => [
                'it-CH' => 'Esportazioni Studio',
                'de-CH' => 'Studio-Exporte',
                'fr-CH' => 'Exports Cabinet',
                'en' => 'Studio Exports',
                'it-IT' => 'Esportazioni Studio',
            ],

            // Tabs
            'fiduciaryPortal.tab.overview' => [
                'it-CH' => 'Clienti & Mandati',
                'de-CH' => 'Mandanten',
                'fr-CH' => 'Mandats Clients',
                'en' => 'Clients & Mandates',
                'it-IT' => 'Clienti & Mandati',
            ],
            'fiduciaryPortal.tab.journals' => [
                'it-CH' => 'Prima Nota & Verifica',
                'de-CH' => 'Journal & Prüfung',
                'fr-CH' => 'Journal & Vérification',
                'en' => 'Journal & Verification',
                'it-IT' => 'Prima Nota & Visto',
            ],
            'fiduciaryPortal.tab.ledger' => [
                'it-CH' => 'Mastrini Contabili',
                'de-CH' => 'Kontoblätter',
                'fr-CH' => 'Grands Livres',
                'en' => 'Account Ledger',
                'it-IT' => 'Mastrini Contabili',
            ],
            'fiduciaryPortal.tab.transcoding' => [
                'it-CH' => 'Mappatura Piano dei Conti',
                'de-CH' => 'Konten-Transcodierung',
                'fr-CH' => 'Matrice de Transcodage',
                'en' => 'Chart Transcoding',
                'it-IT' => 'Mappatura Piano dei Conti',
            ],
            'fiduciaryPortal.tab.exports' => [
                'it-CH' => 'Esportazioni Studio',
                'de-CH' => 'Studio-Exporte',
                'fr-CH' => 'Exports Cabinet',
                'en' => 'Studio Exports',
                'it-IT' => 'Esportazioni Studio',
            ],
            'fiduciaryPortal.tab.periods' => [
                'it-CH' => 'Blocco Periodi',
                'de-CH' => 'Periodensperre',
                'fr-CH' => 'Verrouillage Périodes',
                'en' => 'Period Locks',
                'it-IT' => 'Chiusura & Blocco Periodi',
            ],
            'fiduciaryPortal.tab.notes' => [
                'it-CH' => 'Registro delle Note',
                'de-CH' => 'Notizregister',
                'fr-CH' => 'Registre des Notes',
                'en' => 'Notes Register',
                'it-IT' => 'Registro delle Note',
            ],
            'fiduciaryPortal.tab.dossier' => [
                'it-CH' => 'Fascicolo Bilancio & Audit',
                'de-CH' => 'Bilanzdossier & Audit',
                'fr-CH' => 'Dossier Bilan & Audit',
                'en' => 'Balance Sheet Dossier & Audit',
                'it-IT' => 'Fascicolo Bilancio & Audit',
            ],

            // Badges & Statuses
            'fiduciaryPortal.badge.verified' => [
                'it-CH' => 'Verificato dal Fiduciario',
                'de-CH' => 'Vom Treuhänder geprüft',
                'fr-CH' => 'Vérifié par le Fiduciaire',
                'en' => 'Fiduciary Verified',
                'it-IT' => 'Verificato dal Commercialista',
            ],
            'fiduciaryPortal.badge.unverified' => [
                'it-CH' => 'Da Verificare',
                'de-CH' => 'Zu prüfen',
                'fr-CH' => 'À vérifier',
                'en' => 'Pending Review',
                'it-IT' => 'Da Verificare',
            ],
            'fiduciaryPortal.badge.periodOpen' => [
                'it-CH' => 'Aperto',
                'de-CH' => 'Offen',
                'fr-CH' => 'Ouvert',
                'en' => 'Open',
                'it-IT' => 'Aperto',
            ],
            'fiduciaryPortal.badge.periodLockedByFiduciary' => [
                'it-CH' => 'Bloccato dal Fiduciario',
                'de-CH' => 'Durch Treuhänder gesperrt',
                'fr-CH' => 'Verrouillé par le Fiduciaire',
                'en' => 'Locked by Fiduciary',
                'it-IT' => 'Bloccato dal Commercialista',
            ],
            'fiduciaryPortal.badge.periodClosed' => [
                'it-CH' => 'Chiuso',
                'de-CH' => 'Abgeschlossen',
                'fr-CH' => 'Clôturé',
                'en' => 'Closed',
                'it-IT' => 'Chiuso',
            ],
            'fiduciaryPortal.badge.noteOpen' => [
                'it-CH' => 'Aperta',
                'de-CH' => 'Offen',
                'fr-CH' => 'Ouverte',
                'en' => 'Open',
                'it-IT' => 'Aperta',
            ],
            'fiduciaryPortal.badge.noteResolved' => [
                'it-CH' => 'Risolta',
                'de-CH' => 'Gelöst',
                'fr-CH' => 'Résolue',
                'en' => 'Resolved',
                'it-IT' => 'Risolta',
            ],
            'fiduciaryPortal.badge.mapped' => [
                'it-CH' => 'Mappato',
                'de-CH' => 'Zugeordnet',
                'fr-CH' => 'Mappé',
                'en' => 'Mapped',
                'it-IT' => 'Mappato',
            ],
            'fiduciaryPortal.badge.unmapped' => [
                'it-CH' => 'Non Mappato',
                'de-CH' => 'Nicht zugeordnet',
                'fr-CH' => 'Non mappé',
                'en' => 'Unmapped',
                'it-IT' => 'Non Mappato',
            ],

            // Period Lock Notices
            'fiduciaryPortal.notice.periodLockedBanner' => [
                'it-CH' => 'Periodo contabile bloccato dal Fiduciario: le modifiche, creazioni o cancellazioni retroattive delle scritture in questo periodo sono disabilitate.',
                'de-CH' => 'Buchungsperiode durch Treuhänder gesperrt: Nachträgliche Änderungen, Erstellungen oder Löschungen von Buchungen sind blockiert.',
                'fr-CH' => 'Période comptable verrouillée par le fiduciaire : toute création, modification ou suppression rétroactive est bloquée.',
                'en' => 'Accounting period locked by Fiduciary: retroactive creation, editing, or deletion of journal entries in locked periods is strictly blocked.',
                'it-IT' => 'Periodo contabile bloccato dal Commercialista: le modifiche, creazioni o cancellazioni retroattive sono disabilitate.',
            ],

            // Columns & Filters
            'fiduciaryPortal.col.client' => [
                'it-CH' => 'Azienda Cliente',
                'de-CH' => 'Mandant',
                'fr-CH' => 'Société Cliente',
                'en' => 'Client Company',
                'it-IT' => 'Azienda Cliente',
            ],
            'fiduciaryPortal.col.jurisdiction' => [
                'it-CH' => 'Giurisdizione',
                'de-CH' => 'Rechtsraum',
                'fr-CH' => 'Juridiction',
                'en' => 'Jurisdiction',
                'it-IT' => 'Giurisdizione',
            ],
            'fiduciaryPortal.col.entryNumber' => [
                'it-CH' => 'N. Registrazione',
                'de-CH' => 'Belegnummer',
                'fr-CH' => 'N° Écriture',
                'en' => 'Entry #',
                'it-IT' => 'N. Registrazione',
            ],
            'fiduciaryPortal.col.postingDate' => [
                'it-CH' => 'Data Registrazione',
                'de-CH' => 'Buchungsdatum',
                'fr-CH' => 'Date Comptable',
                'en' => 'Posting Date',
                'it-IT' => 'Data Registrazione',
            ],
            'fiduciaryPortal.col.description' => [
                'it-CH' => 'Descrizione',
                'de-CH' => 'Buchungstext',
                'fr-CH' => 'Libellé',
                'en' => 'Description',
                'it-IT' => 'Descrizione',
            ],
            'fiduciaryPortal.col.amount' => [
                'it-CH' => 'Importo',
                'de-CH' => 'Betrag',
                'fr-CH' => 'Montant',
                'en' => 'Amount',
                'it-IT' => 'Importo',
            ],
            'fiduciaryPortal.col.status' => [
                'it-CH' => 'Stato',
                'de-CH' => 'Status',
                'fr-CH' => 'Statut',
                'en' => 'Status',
                'it-IT' => 'Stato',
            ],
            'fiduciaryPortal.col.sourceAccount' => [
                'it-CH' => 'Conto Aziendale',
                'de-CH' => 'Firmenkonto',
                'fr-CH' => 'Compte Entreprise',
                'en' => 'Company Account',
                'it-IT' => 'Conto Aziendale',
            ],
            'fiduciaryPortal.col.studioAccountCode' => [
                'it-CH' => 'Codice Conto Studio',
                'de-CH' => 'Treuhand-Kontonr.',
                'fr-CH' => 'Code Compte Cabinet',
                'en' => 'Studio Account Code',
                'it-IT' => 'Codice Conto Studio',
            ],
            'fiduciaryPortal.col.studioAccountName' => [
                'it-CH' => 'Descrizione Conto Studio',
                'de-CH' => 'Treuhand-Kontoname',
                'fr-CH' => 'Libellé Compte Cabinet',
                'en' => 'Studio Account Name',
                'it-IT' => 'Descrizione Conto Studio',
            ],
            'fiduciaryPortal.col.studioVatCode' => [
                'it-CH' => 'Codice IVA/MWST Studio',
                'de-CH' => 'Studio-MWST-Code',
                'fr-CH' => 'Code TVA Cabinet',
                'en' => 'Studio VAT Code',
                'it-IT' => 'Codice IVA Studio',
            ],
            'fiduciaryPortal.col.studioCostCenter' => [
                'it-CH' => 'Centro di Costo Studio',
                'de-CH' => 'Studio-Kostenstelle',
                'fr-CH' => 'Centre de Coût Cabinet',
                'en' => 'Studio Cost Center',
                'it-IT' => 'Centro di Costo Studio',
            ],
            'fiduciaryPortal.col.period' => [
                'it-CH' => 'Periodo Contabile',
                'de-CH' => 'Buchungsperiode',
                'fr-CH' => 'Période Comptable',
                'en' => 'Accounting Period',
                'it-IT' => 'Periodo Contabile',
            ],
            'fiduciaryPortal.col.lockReason' => [
                'it-CH' => 'Motivo Blocco',
                'de-CH' => 'Sperrgrund',
                'fr-CH' => 'Motif du Verrouillage',
                'en' => 'Lock Reason',
                'it-IT' => 'Motivo Blocco',
            ],
            'fiduciaryPortal.col.actions' => [
                'it-CH' => 'Azioni',
                'de-CH' => 'Aktionen',
                'fr-CH' => 'Actions',
                'en' => 'Actions',
                'it-IT' => 'Azioni',
            ],

            // Filters
            'fiduciaryPortal.filter.fiscalYear' => [
                'it-CH' => 'Anno Fiscale',
                'de-CH' => 'Geschäftsjahr',
                'fr-CH' => 'Exercice Fiscal',
                'en' => 'Fiscal Year',
                'it-IT' => 'Anno Fiscale',
            ],
            'fiduciaryPortal.filter.period' => [
                'it-CH' => 'Periodo / Mese',
                'de-CH' => 'Periode / Monat',
                'fr-CH' => 'Période / Mois',
                'en' => 'Period / Month',
                'it-IT' => 'Periodo / Mese',
            ],
            'fiduciaryPortal.filter.verificationStatus' => [
                'it-CH' => 'Stato Verifica',
                'de-CH' => 'Prüfstatus',
                'fr-CH' => 'Statut Vérification',
                'en' => 'Verification Status',
                'it-IT' => 'Stato Visto',
            ],
            'fiduciaryPortal.filter.all' => [
                'it-CH' => 'Tutte',
                'de-CH' => 'Alle',
                'fr-CH' => 'Toutes',
                'en' => 'All',
                'it-IT' => 'Tutte',
            ],
            'fiduciaryPortal.filter.search' => [
                'it-CH' => 'Cerca numero, documento o descrizione...',
                'de-CH' => 'Suche Belegnummer, Dokument oder Text...',
                'fr-CH' => 'Rechercher numéro, document ou libellé...',
                'en' => 'Search entry #, document, or description...',
                'it-IT' => 'Cerca registrazione, documento o descrizione...',
            ],
            'fiduciaryPortal.filter.unmappedOnly' => [
                'it-CH' => 'Solo conti non mappati',
                'de-CH' => 'Nur nicht zugeordnete Konten',
                'fr-CH' => 'Comptes non mappés uniquement',
                'en' => 'Unmapped accounts only',
                'it-IT' => 'Solo conti non mappati',
            ],
            'fiduciaryPortal.filter.onlyVerified' => [
                'it-CH' => 'Esporta solo scritture verificate dal fiduciario',
                'de-CH' => 'Nur vom Treuhänder geprüfte Buchungen exportieren',
                'fr-CH' => 'Exporter uniquement les écritures vérifiées',
                'en' => 'Export fiduciary-verified entries only',
                'it-IT' => 'Esporta solo scritture verificate dal commercialista',
            ],
            'fiduciaryPortal.filter.applyTranscoding' => [
                'it-CH' => 'Applica transcodifica piano dei conti studio',
                'de-CH' => 'Studio-Kontenzuordnung anwenden',
                'fr-CH' => 'Appliquer le transcodage du plan comptable cabinet',
                'en' => 'Apply studio chart-of-accounts transcoding',
                'it-IT' => 'Applica mappatura piano dei conti studio',
            ],

            // Actions & Modals
            'fiduciaryPortal.action.verify' => [
                'it-CH' => 'Verifica',
                'de-CH' => 'Prüfen',
                'fr-CH' => 'Vérifier',
                'en' => 'Verify',
                'it-IT' => 'Apponi Visto',
            ],
            'fiduciaryPortal.action.unverify' => [
                'it-CH' => 'Rimuovi Verifica',
                'de-CH' => 'Prüfung aufheben',
                'fr-CH' => 'Retirer Vérification',
                'en' => 'Unverify',
                'it-IT' => 'Rimuovi Visto',
            ],
            'fiduciaryPortal.action.batchVerify' => [
                'it-CH' => 'Verifica Selezionate',
                'de-CH' => 'Auswahl prüfen',
                'fr-CH' => 'Vérifier la sélection',
                'en' => 'Verify Selected',
                'it-IT' => 'Verifica Selezionate',
            ],
            'fiduciaryPortal.action.batchUnverify' => [
                'it-CH' => 'Rimuovi Verifica Selezionate',
                'de-CH' => 'Auswahl-Prüfung aufheben',
                'fr-CH' => 'Retirer vérification sélection',
                'en' => 'Unverify Selected',
                'it-IT' => 'Rimuovi Visto Selezionate',
            ],
            'fiduciaryPortal.action.runLedger' => [
                'it-CH' => 'Consulta Mastrino',
                'de-CH' => 'Kontoblatt laden',
                'fr-CH' => 'Consulter Grand Livre',
                'en' => 'Load Account Ledger',
                'it-IT' => 'Consulta Mastrino',
            ],
            'fiduciaryPortal.action.saveRule' => [
                'it-CH' => 'Salva Regola',
                'de-CH' => 'Regel speichern',
                'fr-CH' => 'Enregistrer',
                'en' => 'Save Rule',
                'it-IT' => 'Salva Regola',
            ],
            'fiduciaryPortal.action.saveBulk' => [
                'it-CH' => 'Salva Mappature Massivamente',
                'de-CH' => 'Alle Zuordnungen speichern',
                'fr-CH' => 'Enregistrer en masse',
                'en' => 'Save All Mappings',
                'it-IT' => 'Salva Mappatura Massiva',
            ],
            'fiduciaryPortal.action.seedDefaults' => [
                'it-CH' => 'Genera Mappatura Standard',
                'de-CH' => 'Standardzuordnung generieren',
                'fr-CH' => 'Générer correspondances standard',
                'en' => 'Generate Standard Mapping',
                'it-IT' => 'Genera Mappatura Standard',
            ],
            'fiduciaryPortal.action.validateExport' => [
                'it-CH' => 'Verifica Pre-Esportazione',
                'de-CH' => 'Vorab-Validierung',
                'fr-CH' => 'Validation Pré-Export',
                'en' => 'Pre-Export Check',
                'it-IT' => 'Verifica Pre-Esportazione',
            ],
            'fiduciaryPortal.action.generateExport' => [
                'it-CH' => 'Genera Esportazione Studio',
                'de-CH' => 'Studio-Export generieren',
                'fr-CH' => 'Générer Export Cabinet',
                'en' => 'Generate Studio Export',
                'it-IT' => 'Genera Esportazione Studio',
            ],
            'fiduciaryPortal.action.download' => [
                'it-CH' => 'Scarica',
                'de-CH' => 'Herunterladen',
                'fr-CH' => 'Télécharger',
                'en' => 'Download',
                'it-IT' => 'Scarica',
            ],
            'fiduciaryPortal.action.lockPeriod' => [
                'it-CH' => 'Blocca Periodo',
                'de-CH' => 'Periode sperren',
                'fr-CH' => 'Verrouiller Période',
                'en' => 'Lock Period',
                'it-IT' => 'Blocca Periodo',
            ],
            'fiduciaryPortal.action.unlockPeriod' => [
                'it-CH' => 'Sblocca Periodo',
                'de-CH' => 'Periode entsperren',
                'fr-CH' => 'Déverrouiller Période',
                'en' => 'Unlock Period',
                'it-IT' => 'Sblocca Periodo',
            ],
            'fiduciaryPortal.action.openNotes' => [
                'it-CH' => 'Note',
                'de-CH' => 'Notizen',
                'fr-CH' => 'Notes',
                'en' => 'Notes',
                'it-IT' => 'Note',
            ],
            'fiduciaryPortal.action.addNote' => [
                'it-CH' => 'Invia Nota',
                'de-CH' => 'Notiz senden',
                'fr-CH' => 'Envoyer Note',
                'en' => 'Post Note',
                'it-IT' => 'Invia Nota',
            ],
            'fiduciaryPortal.action.resolveNote' => [
                'it-CH' => 'Segna come Risolta',
                'de-CH' => 'Als gelöst markieren',
                'fr-CH' => 'Marquer résolue',
                'en' => 'Mark Resolved',
                'it-IT' => 'Segna Risolta',
            ],
            'fiduciaryPortal.action.reopenNote' => [
                'it-CH' => 'Riapri Nota',
                'de-CH' => 'Wieder öffnen',
                'fr-CH' => 'Rouvrir',
                'en' => 'Reopen',
                'it-IT' => 'Riapri',
            ],
            'fiduciaryPortal.action.previewDossier' => [
                'it-CH' => 'Aggiorna Anteprima Fascicolo',
                'de-CH' => 'Dossier-Vorschau laden',
                'fr-CH' => 'Aperçu du Dossier',
                'en' => 'Refresh Dossier Preview',
                'it-IT' => 'Anteprima Fascicolo',
            ],
            'fiduciaryPortal.action.downloadDossier' => [
                'it-CH' => 'Scarica Fascicolo Digitale di Bilancio (.ZIP)',
                'de-CH' => 'Digitales Bilanzdossier herunterladen (.ZIP)',
                'fr-CH' => 'Télécharger Dossier Numérique de Bilan (.ZIP)',
                'en' => 'Download Digital Balance Sheet Dossier (.ZIP)',
                'it-IT' => 'Scarica Fascicolo Digitale di Bilancio (.ZIP)',
            ],

            // Modals & Notes
            'fiduciaryPortal.modal.lockTitle' => [
                'it-CH' => 'Chiusura Periodo / Blocco Fiduciario',
                'de-CH' => 'Periodensperre durch Treuhänder',
                'fr-CH' => 'Clôture Période / Verrouillage Fiduciaire',
                'en' => 'Period Lock / Fiduciary Freeze',
                'it-IT' => 'Chiusura Periodo / Blocco Commercialista',
            ],
            'fiduciaryPortal.modal.unlockTitle' => [
                'it-CH' => 'Rimozione Blocco Fiduciario',
                'de-CH' => 'Treuhänder-Sperre aufheben',
                'fr-CH' => 'Lever le Verrouillage Fiduciaire',
                'en' => 'Remove Fiduciary Period Lock',
                'it-IT' => 'Rimozione Blocco Periodo',
            ],
            'fiduciaryPortal.modal.reasonLabel' => [
                'it-CH' => 'Motivazione (registrata nel log di audit)',
                'de-CH' => 'Begründung (im Audit-Log protokolliert)',
                'fr-CH' => 'Motif (enregistré dans le journal d\'audit)',
                'en' => 'Reason (recorded in audit trail)',
                'it-IT' => 'Motivazione (registrata nel log di audit)',
            ],
            'fiduciaryPortal.notes.drawerTitle' => [
                'it-CH' => 'Registro delle Note Ancorato alla Scrittura',
                'de-CH' => 'Verankertes Notizregister zur Buchung',
                'fr-CH' => 'Registre des Notes Ancré à l\'Écriture',
                'en' => 'Anchored Entry Notes Thread',
                'it-IT' => 'Registro delle Note sulla Scrittura',
            ],
            'fiduciaryPortal.notes.placeholder' => [
                'it-CH' => 'Scrivi una richiesta di chiarimento o nota di revisione...',
                'de-CH' => 'Klärungsanfrage oder Revisionsnotiz eingeben...',
                'fr-CH' => 'Saisir une demande de clarification ou note de révision...',
                'en' => 'Write a clarification request or audit note...',
                'it-IT' => 'Scrivi una richiesta di chiarimento o nota di revisione...',
            ],

            // Validation & Diagnostics
            'fiduciaryPortal.validation.reasonRequired' => [
                'it-CH' => 'Inserisci una motivazione di almeno 3 caratteri.',
                'de-CH' => 'Bitte geben Sie eine Begründung mit mindestens 3 Zeichen ein.',
                'fr-CH' => 'Veuillez saisir un motif d\'au moins 3 caractères.',
                'en' => 'Please enter a reason of at least 3 characters.',
                'it-IT' => 'Inserisci una motivazione di almeno 3 caratteri.',
            ],
            'fiduciaryPortal.validation.unmappedWarning' => [
                'it-CH' => 'Attenzione: alcuni conti movimentati o attivi non hanno una mappatura studio configurata.',
                'de-CH' => 'Warnung: Einige bebuchte oder aktive Konten haben noch keine Studio-Zuordnung.',
                'fr-CH' => 'Attention : certains comptes mouvementés ou actifs n\'ont pas de correspondance cabinet.',
                'en' => 'Warning: some active or posted accounts do not have a studio transcoding mapping.',
                'it-IT' => 'Attenzione: alcuni conti movimentati o attivi non hanno una mappatura studio configurata.',
            ],

            // Export Format Titles & Descriptions (All 10 formats)
            'fiduciaryPortal.exportFormat.abacus_xml.label' => [
                'it-CH' => 'Abacus AbaConnect XML (CH)',
                'de-CH' => 'Abacus AbaConnect XML (CH)',
                'fr-CH' => 'Abacus AbaConnect XML (CH)',
                'en' => 'Abacus AbaConnect XML (CH)',
                'it-IT' => 'Abacus AbaConnect XML (CH)',
            ],
            'fiduciaryPortal.exportFormat.abacus_xml.description' => [
                'it-CH' => 'Tracciato XML AbaConnect per importazione diretta in Abacus FIBU con codici IVA MWST.',
                'de-CH' => 'AbaConnect-XML-Schnittstelle für direkten Import in Abacus FIBU inkl. MWST-Codes.',
                'fr-CH' => 'Format XML AbaConnect pour import direct dans Abacus FIBU avec codes TVA.',
                'en' => 'AbaConnect XML schema for direct Abacus FIBU import with Swiss MWST VAT codes.',
                'it-IT' => 'Tracciato XML AbaConnect per importazione diretta in Abacus FIBU.',
            ],
            'fiduciaryPortal.exportFormat.abacus_ascii.label' => [
                'it-CH' => 'Abacus ASCII / CSV (CH)',
                'de-CH' => 'Abacus ASCII / CSV (CH)',
                'fr-CH' => 'Abacus ASCII / CSV (CH)',
                'en' => 'Abacus ASCII / CSV (CH)',
                'it-IT' => 'Abacus ASCII / CSV (CH)',
            ],
            'fiduciaryPortal.exportFormat.abacus_ascii.description' => [
                'it-CH' => 'Giornale contabile Abacus in formato ASCII tabulare.',
                'de-CH' => 'Abacus-Buchungsjournal im ASCII-Format.',
                'fr-CH' => 'Journal comptable Abacus au format ASCII.',
                'en' => 'Abacus ASCII tabular accounting journal format.',
                'it-IT' => 'Giornale contabile Abacus in formato ASCII.',
            ],
            'fiduciaryPortal.exportFormat.topal.label' => [
                'it-CH' => 'Topal Finanzbuchhaltung (CH)',
                'de-CH' => 'Topal Finanzbuchhaltung (CH)',
                'fr-CH' => 'Topal Comptabilité (CH)',
                'en' => 'Topal Accounting CSV (CH)',
                'it-IT' => 'Topal Contabilità (CH)',
            ],
            'fiduciaryPortal.exportFormat.topal.description' => [
                'it-CH' => 'Esportazione CSV compatibile con Topal Solutions Svizzera.',
                'de-CH' => 'CSV-Export für Topal Solutions Finanzbuchhaltung Schweiz.',
                'fr-CH' => 'Export CSV compatible avec Topal Solutions Suisse.',
                'en' => 'CSV export compatible with Swiss Topal Solutions accounting.',
                'it-IT' => 'Esportazione CSV compatibile con Topal Solutions Svizzera.',
            ],
            'fiduciaryPortal.exportFormat.sage50_extra.label' => [
                'it-CH' => 'Sage 50 Extra (.taf) (CH)',
                'de-CH' => 'Sage 50 Extra (.taf) (CH)',
                'fr-CH' => 'Sage 50 Extra (.taf) (CH)',
                'en' => 'Sage 50 Extra (.taf) (CH)',
                'it-IT' => 'Sage 50 Extra (.taf) (CH)',
            ],
            'fiduciaryPortal.exportFormat.sage50_extra.description' => [
                'it-CH' => 'Tracciato di importazione Sage 50 Extra Svizzera con doppia valuta.',
                'de-CH' => 'Sage 50 Extra Importformat Schweiz mit Fremdwährungsbeträgen.',
                'fr-CH' => 'Format d\'import Sage 50 Extra Suisse avec montants en devises.',
                'en' => 'Swiss Sage 50 Extra .taf booking import format with multi-currency amounts.',
                'it-IT' => 'Tracciato di importazione Sage 50 Extra Svizzera.',
            ],
            'fiduciaryPortal.exportFormat.swiss_fiduciary_universal.label' => [
                'it-CH' => 'Formato Universale Fiduciario Svizzero (KMU)',
                'de-CH' => 'Schweizer Treuhand-Universalformat (KMU)',
                'fr-CH' => 'Format Universel Fiduciaire Suisse (PME)',
                'en' => 'Swiss Fiduciary Universal Format (KMU)',
                'it-IT' => 'Formato Universale Fiduciario Svizzero (KMU)',
            ],
            'fiduciaryPortal.exportFormat.swiss_fiduciary_universal.description' => [
                'it-CH' => 'Layout completo KMU / MWST / multivaluta con IDI/UID e stato verifica.',
                'de-CH' => 'Vollständiges KMU / MWST / Mehrwährungs-Layout mit UID und Prüfstatus.',
                'fr-CH' => 'Mise en page complète PME / TVA / multidevise avec IDE et statut de vérification.',
                'en' => 'Complete Swiss KMU / MWST / multi-currency ledger layout with UID and verification status.',
                'it-IT' => 'Layout completo KMU / MWST / multivaluta svizzero.',
            ],
            'fiduciaryPortal.exportFormat.teamsystem.label' => [
                'it-CH' => 'TeamSystem Gamma / Bridge Prima Nota (IT)',
                'de-CH' => 'TeamSystem Gamma / Bridge Prima Nota (IT)',
                'fr-CH' => 'TeamSystem Gamma / Bridge Prima Nota (IT)',
                'en' => 'TeamSystem Gamma / Bridge Prima Nota (IT)',
                'it-IT' => 'TeamSystem Gamma / Alyante / Bridge (IT)',
            ],
            'fiduciaryPortal.exportFormat.teamsystem.description' => [
                'it-CH' => 'Tracciato Prima Nota per TeamSystem Gamma, Alyante e Studio.',
                'de-CH' => 'Prima-Nota-Export für TeamSystem Gamma, Alyante und Studio.',
                'fr-CH' => 'Format Prima Nota pour TeamSystem Gamma, Alyante et Studio.',
                'en' => 'Prima Nota export format for Italian TeamSystem Gamma, Alyante, and Studio.',
                'it-IT' => 'Tracciato Prima Nota per TeamSystem Gamma, Alyante e Studio.',
            ],
            'fiduciaryPortal.exportFormat.zucchetti.label' => [
                'it-CH' => 'Zucchetti Prima Nota (IT)',
                'de-CH' => 'Zucchetti Prima Nota (IT)',
                'fr-CH' => 'Zucchetti Prima Nota (IT)',
                'en' => 'Zucchetti Prima Nota (IT)',
                'it-IT' => 'Zucchetti Ago / AdHoc / Omnia (IT)',
            ],
            'fiduciaryPortal.exportFormat.zucchetti.description' => [
                'it-CH' => 'Tracciato record H/D Prima Nota per software Zucchetti.',
                'de-CH' => 'H/D-Satzformat Prima Nota für Zucchetti.',
                'fr-CH' => 'Format d\'enregistrement H/D Prima Nota pour Zucchetti.',
                'en' => 'H/D record format Prima Nota for Italian Zucchetti suites.',
                'it-IT' => 'Tracciato record H/D Prima Nota per suite Zucchetti.',
            ],
            'fiduciaryPortal.exportFormat.profis.label' => [
                'it-CH' => 'Sistemi Profis Prima Nota (IT)',
                'de-CH' => 'Sistemi Profis Prima Nota (IT)',
                'fr-CH' => 'Sistemi Profis Prima Nota (IT)',
                'en' => 'Sistemi Profis Prima Nota (IT)',
                'it-IT' => 'Sistemi Profis Prima Nota (IT)',
            ],
            'fiduciaryPortal.exportFormat.profis.description' => [
                'it-CH' => 'Flusso contabile Prima Nota per Sistemi Profis.',
                'de-CH' => 'Prima-Nota-Buchungsexport für Sistemi Profis.',
                'fr-CH' => 'Flux comptable Prima Nota pour Sistemi Profis.',
                'en' => 'Prima Nota accounting export for Italian Sistemi Profis.',
                'it-IT' => 'Flusso contabile Prima Nota per Sistemi Profis.',
            ],
            'fiduciaryPortal.exportFormat.datev_koinos.label' => [
                'it-CH' => 'DATEV / DATEV Koinos EXTF 700',
                'de-CH' => 'DATEV / DATEV Koinos EXTF 700',
                'fr-CH' => 'DATEV / DATEV Koinos EXTF 700',
                'en' => 'DATEV / DATEV Koinos EXTF 700',
                'it-IT' => 'DATEV / DATEV Koinos EXTF 700',
            ],
            'fiduciaryPortal.exportFormat.datev_koinos.description' => [
                'it-CH' => 'Buchungsstapel DATEV EXTF 700 (Windows-1252) con mappatura conti numerici.',
                'de-CH' => 'DATEV EXTF 700 Buchungsstapel (Windows-1252) mit numerischer Kontenzuordnung.',
                'fr-CH' => 'Buchungsstapel DATEV EXTF 700 (Windows-1252) avec comptes numériques mappés.',
                'en' => 'DATEV EXTF 700 Buchungsstapel (Windows-1252) with transcoded numeric accounts.',
                'it-IT' => 'Tracciato DATEV / DATEV Koinos EXTF 700 (Windows-1252) con conti transcodificati.',
            ],
            'fiduciaryPortal.exportFormat.universal_csv.label' => [
                'it-CH' => 'Universale ASCII / CSV Doppia Partita',
                'de-CH' => 'Universal ASCII / CSV Doppelte Buchführung',
                'fr-CH' => 'Universel ASCII / CSV Partie Double',
                'en' => 'Universal ASCII / CSV Double-Entry',
                'it-IT' => 'Universale ASCII / CSV Partita Doppia',
            ],
            'fiduciaryPortal.exportFormat.universal_csv.description' => [
                'it-CH' => 'Esportazione CSV universale UTF-8 con conti originali, conti studio, IVA e centri di costo.',
                'de-CH' => 'Universeller UTF-8-CSV-Export mit Original- und Studiokonten, MWST und Kostenstellen.',
                'fr-CH' => 'Export CSV universel UTF-8 avec comptes d\'origine, comptes cabinet, TVA et centres de coûts.',
                'en' => 'Universal UTF-8 CSV export with source/studio accounts, VAT codes, and cost centers.',
                'it-IT' => 'Esportazione CSV universale UTF-8 con conti originali, conti studio, IVA e centri di costo.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $resolved = match ($locale) {
            'it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT' => $locale,
            'it' => 'it-IT',
            'de' => 'de-CH',
            'fr' => 'fr-CH',
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        $out = [];
        foreach (self::definitions() as $key => $locales) {
            $out[$key] = $locales[$resolved];
        }

        return $out;
    }
}
