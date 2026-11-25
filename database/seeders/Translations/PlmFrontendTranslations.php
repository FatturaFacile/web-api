<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings for PLM & Engineering Change (Mod 168): ECR/ECO/ECN Kanban, 4-department approvals,
 * stock disposition cutover, CAD-to-mBOM import wizard, Redline BOM Diff & Cost Roll-up,
 * Where-Used multi-level impact, 3D STEP/STL & PDF viewer, MES obsolete watermark, and CE/OMacch dossier.
 * Each entry is [it, en, de, fr]; Italian serves it-CH & it-IT, German serves de-CH, French serves fr-CH.
 */
final class PlmFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.PLM_CAD_IMPORT' => ['Importazione CAD (eBOM -> mBOM)', 'CAD Import (eBOM -> mBOM)', 'CAD-Import (eBOM -> mBOM)', 'Import CAO (eBOM -> mBOM)'],
            'NAV.PLM_BOM_DIFF' => ['Confronto Revisioni (Redline)', 'Revision Diff (Redline)', 'Revisionsvergleich (Redline)', 'Comparateur Révisions (Redline)'],
            'NAV.PLM_WHERE_USED' => ['Analisi Impatto Where-Used', 'Where-Used Impact Analysis', 'Verwendungsnachweis & Auswirkung', 'Analyse d’impact Where-Used'],

            'PAGE_TITLE.PLM_CAD_IMPORT' => ['Sincronizzazione CAD eBOM -> mBOM', 'CAD eBOM -> mBOM Sync', 'CAD eBOM -> mBOM Synchronisation', 'Synchronisation CAO eBOM -> mBOM'],
            'PAGE_TITLE.PLM_BOM_DIFF' => ['Comparatore Visuale Distinte', 'Visual BOM Revision Comparator', 'Visueller Stücklisten-Vergleich', 'Comparateur visuel de nomenclatures'],
            'PAGE_TITLE.PLM_WHERE_USED' => ['Analisi Impatto Where-Used Multilivello', 'Multi-Level Where-Used Impact', 'Mehrstufige Verwendungsanalyse', 'Analyse d’impact Where-Used multiniveau'],

            'PLM.VIEW_TABLE' => ['Vista Tabella', 'Table View', 'Tabellenansicht', 'Vue Tableau'],
            'PLM.VIEW_KANBAN' => ['Kanban ECR / ECO / ECN', 'ECR / ECO / ECN Kanban', 'ECR / ECO / ECN Kanban', 'Kanban ECR / ECO / ECN'],
            'PLM.STAGE_DRAFT' => ['Bozza ECR', 'Draft ECR', 'ECR-Entwurf', 'Brouillon ECR'],
            'PLM.STAGE_ECRSUBMITTED' => ['ECR Inviata', 'ECR Submitted', 'ECR eingereicht', 'ECR soumise'],
            'PLM.STAGE_UNDEREVALUATION' => ['In Valutazione', 'Under Evaluation', 'In Bewertung', 'En évaluation'],
            'PLM.STAGE_ECOAPPROVED' => ['ECO Approvato', 'ECO Approved', 'ECO genehmigt', 'ECO approuvé'],
            'PLM.STAGE_ECNRELEASED' => ['ECN Rilasciato', 'ECN Released', 'ECN freigegeben', 'ECN publié'],
            'PLM.STAGE_CLOSED' => ['Chiuso', 'Closed', 'Abgeschlossen', 'Clôturé'],
            'PLM.STAGE_REJECTED' => ['Rifiutato', 'Rejected', 'Abgelehnt', 'Rejeté'],
            'PLM.STAGE_CANCELLED' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],

            'PLM.CHANGE_REASON' => ['Motivo Modifica', 'Change Reason', 'Änderungsgrund', 'Motif de modification'],
            'PLM.REASON_COSTREDUCTION' => ['Riduzione Costi', 'Cost Reduction', 'Kostensenkung', 'Réduction des coûts'],
            'PLM.REASON_QUALITYSAFETYFIX' => ['Sicurezza / Qualità (Taglio Immediato)', 'Quality / Safety Fix (Immediate Cutover)', 'Qualität / Sicherheit (Sofortiger Schnitt)', 'Qualité / Sécurité (Bascule immédiate)'],
            'PLM.REASON_COMPONENTOBSOLESCENCE' => ['Obsolescenza Componente', 'Component Obsolescence', 'Komponenten-Abkündigung', 'Obsolescence composant'],
            'PLM.REASON_CUSTOMERREQUEST' => ['Richiesta Cliente', 'Customer Request', 'Kundenanforderung', 'Demande client'],
            'PLM.REASON_REGULATORYCOMPLIANCE' => ['Conformità Normativa CE/OMacch', 'Regulatory Compliance CE/OMacch', 'Normenkonformität CE/MaschV', 'Conformité réglementaire CE/OMacch'],
            'PLM.REASON_MANUFACTURINGOPTIMIZATION' => ['Ottimizzazione Produzione', 'Manufacturing Optimization', 'Fertigungsoptimierung', 'Optimisation fabrication'],

            'PLM.DISPOSITION_RULE' => ['Regola Giacenze Residue', 'Stock Disposition Rule', 'Restbestandsregel', 'Règle de gestion des stocks'],
            'PLM.DISP_USEUP_ESAURIMENTOSCORTE' => ['Phase-Out ad Esaurimento Scorte (Consuma Rev. A)', 'Phase-Out Use Up Stock (Consume Rev. A first)', 'Phase-Out Aufbrauchen (Rev. A zuerst)', 'Écoulement des stocks (Consommer Rév. A)'],
            'PLM.DISP_REWORKINHOUSE' => ['Rilavora Internamente', 'Rework In-House', 'Interne Nacharbeit', 'Retravailler en interne'],
            'PLM.DISP_SCRAPIMMEDIATELY' => ['Rottama / Blocca Immediatamente (Sicurezza)', 'Scrap / Quarantine Immediately (Safety)', 'Sofort verschrotten / sperren (Sicherheit)', 'Mettre au rebut / Quarantaine immédiate'],

            'PLM.SAFETY_CUTOVER_BANNER' => ['Taglio Immediato per Sicurezza: quarantena lotti e blocco immediato disegni MES.', 'Immediate Safety Cutover: quarantines lots and blocks MES drawings immediately.', 'Sofortiger Sicherheitsschnitt: Chargen sperren und MES-Zeichnungen sofort blockieren.', 'Bascule immédiate sécurité : mise en quarantaine des lots et blocage des plans MES.'],
            'PLM.EXECUTE_CUTOVER' => ['Esegui Cutover & Rilascia ECN', 'Execute Cutover & Release ECN', 'Umstellung ausführen & ECN freigeben', 'Exécuter la bascule & publier ECN'],
            'PLM.ECN_NUMBER' => ['Numero ECN', 'ECN Number', 'ECN-Nummer', 'Numéro ECN'],
            'PLM.CUTOVER_LOT' => ['Taglio per Lotto / Seriale', 'Lot / Serial Cutover', 'Chargen- / Serienschnitt', 'Bascule par lot / série'],
            'PLM.CUTOVER_DONE' => ['Cutover revisione eseguito ed ECN rilasciato', 'Revision cutover executed and ECN released', 'Revisionsumstellung ausgeführt und ECN freigegeben', 'Bascule de révision exécutée et ECN publié'],

            'PLM.DEPT_APPROVAL_MATRIX' => ['Matrice Approvazioni Dipartimentali (4 Firme)', 'Departmental Approval Matrix (4 Sign-Offs)', 'Abteilungs-Freigabematrix (4 Signaturen)', 'Matrice d’approbation départementale (4 signatures)'],
            'PLM.DEPT_ENGINEERING' => ['Ufficio Tecnico', 'Engineering', 'Technisches Büro', 'Bureau d’études'],
            'PLM.DEPT_QUALITY' => ['Qualità', 'Quality', 'Qualität', 'Qualité'],
            'PLM.DEPT_PURCHASING' => ['Acquisti', 'Purchasing', 'Einkauf', 'Achats'],
            'PLM.DEPT_PRODUCTION' => ['Produzione', 'Production', 'Produktion', 'Production'],
            'PLM.SIGN_OFF_HASH' => ['Impronta Firma SHA-256', 'Sign-Off SHA-256 Hash', 'Signatur-Hash SHA-256', 'Empreinte SHA-256'],
            'PLM.SIGNOFF_RECORDED' => ['Firma dipartimentale registrata', 'Department sign-off recorded', 'Abteilungsfreigabe erfasst', 'Signature départementale enregistrée'],

            'PLM.LIFECYCLE_STATE' => ['Stato Ciclo di Vita', 'Lifecycle State', 'Lebenszyklusstatus', 'Statut cycle de vie'],
            'PLM.LC_INDESIGN_PROTOTYPE' => ['In Progettazione / Prototipo', 'In Design / Prototype', 'In Entwicklung / Prototyp', 'En conception / Prototype'],
            'PLM.LC_UNDERREVIEW' => ['In Revisione', 'Under Review', 'In Prüfung', 'En révision'],
            'PLM.LC_RELEASEDFORPRODUCTION' => ['Rilasciato per Produzione', 'Released for Production', 'Für Produktion freigegeben', 'Publié pour production'],
            'PLM.LC_PHASEOUT_USEUPSTOCK' => ['Phase-Out (Esaurimento Scorte)', 'Phase-Out (Use Up Stock)', 'Auslauf (Bestand aufbrauchen)', 'Fin de vie (Écoulement stock)'],
            'PLM.LC_OBSOLETEBLOCKED' => ['Obsoleto / Bloccato', 'Obsolete / Blocked', 'Obsolet / Gesperrt', 'Obsolète / Bloqué'],

            'PLM.WATERMARK_OBSOLETE' => ['SUPERATO / OBSOLETO', 'SUPERSEDED / OBSOLETE', 'ÜBERHOLT / OBSOLET', 'PÉRIMÉ / OBSOLÈTE'],
            'PLM.WATERMARK_WARNING' => ['Attenzione: questa tavola tecnica è superata. Non utilizzare in produzione.', 'Warning: this engineering drawing is superseded. Do not use in production.', 'Achtung: Diese technische Zeichnung ist überholt. Nicht in der Produktion verwenden.', 'Attention : ce plan technique est périmé. Ne pas utiliser en production.'],

            'PLM.CAD_IMPORT_TITLE' => ['Importazione CAD (eBOM -> mBOM)', 'CAD Import (eBOM -> mBOM)', 'CAD-Import (eBOM -> mBOM)', 'Import CAO (eBOM -> mBOM)'],
            'PLM.CAD_IMPORT_SUBTITLE' => ['Importa distinte CAD (SolidWorks, Inventor, Creo, NX, Eplan, Altium), crea i codici mancanti e confronta eBOM vs mBOM.', 'Import CAD BOMs (SolidWorks, Inventor, Creo, NX, Eplan, Altium), auto-create missing parts and compare eBOM vs mBOM.', 'CAD-Stücklisten importieren, fehlende Artikel anlegen und eBOM vs. mBOM vergleichen.', 'Importer les nomenclatures CAO, créer les articles manquants et comparer eBOM vs mBOM.'],
            'PLM.CAD_SOURCE_SYSTEM' => ['Sistema CAD Sorgente', 'CAD Source System', 'CAD-Quellsystem', 'Système CAO source'],
            'PLM.PRODUCT_CODE' => ['Codice Prodotto Finito', 'Finished Product Code', 'Fertigproduktcode', 'Code produit fini'],
            'PLM.CAD_PAYLOAD_FORMAT' => ['Formato File CAD', 'CAD Payload Format', 'CAD-Dateiformat', 'Format fichier CAO'],
            'PLM.CAD_RAW_PAYLOAD' => ['Contenuto Export CAD (XML / CSV / STEP)', 'CAD Export Payload (XML / CSV / STEP)', 'CAD-Exportinhalt (XML / CSV / STEP)', 'Contenu export CAO (XML / CSV / STEP)'],
            'PLM.CAD_AUTO_CREATE' => ['Crea automaticamente codici mancanti', 'Auto-create missing part codes', 'Fehlende Artikelcodes automatisch anlegen', 'Créer automatiquement les codes manquants'],
            'PLM.CAD_RUN_IMPORT' => ['Analizza & Confronta eBOM vs mBOM', 'Parse & Compare eBOM vs mBOM', 'Analysieren & eBOM vs. mBOM vergleichen', 'Analyser & Comparer eBOM vs mBOM'],
            'PLM.CAD_COMMIT_MBOM' => ['Conferma e Sincronizza su mBOM (Mod 059)', 'Commit & Sync to mBOM (Mod 059)', 'In mBOM übernehmen (Mod 059)', 'Valider & Synchroniser vers mBOM (Mod 059)'],
            'PLM.CAD_IMPORT_SUCCESS' => ['Sessione CAD importata con successo', 'CAD session imported successfully', 'CAD-Sitzung erfolgreich importiert', 'Session CAO importée avec succès'],
            'PLM.CAD_COMMIT_SUCCESS' => ['eBOM sincronizzata su mBOM (Mod 059)', 'eBOM synchronized to mBOM (Mod 059)', 'eBOM mit mBOM synchronisiert (Mod 059)', 'eBOM synchronisée vers mBOM (Mod 059)'],
            'PLM.NEW_PARTS_CREATED' => ['Nuovi Particolari Creati', 'New Parts Created', 'Neu angelegte Teile', 'Nouveaux articles créés'],

            'PLM.DIFF_TITLE' => ['Comparatore Revisioni Distinta (Redline BOM Diff)', 'Visual BOM Revision Comparator (Redline)', 'Visueller Stücklisten-Vergleich (Redline)', 'Comparateur Visuel de Nomenclatures (Redline)'],
            'PLM.DIFF_SUBTITLE' => ['Confronto multilivello Rev. A vs Rev. B con evidenziazione Verde/Rosso/Giallo e Cost Roll-up.', 'Multi-level Rev. A vs Rev. B comparison with Green/Red/Yellow highlights and Cost Roll-up.', 'Mehrstufiger Rev.-A/B-Vergleich mit Grün/Rot/Gelb-Markierung und Kostenaufrollung.', 'Comparaison multiniveau Rév. A vs Rév. B avec surlignage Vert/Rouge/Jaune et cumul des coûts.'],
            'PLM.DIFF_FROM_BOM' => ['Distinta Origine (Rev. A)', 'Source BOM (Rev. A)', 'Quell-Stückliste (Rev. A)', 'Nomenclature source (Rév. A)'],
            'PLM.DIFF_TO_BOM' => ['Distinta Destinazione (Rev. B)', 'Target BOM (Rev. B)', 'Ziel-Stückliste (Rev. B)', 'Nomenclature cible (Rév. B)'],
            'PLM.DIFF_COMPARE' => ['Confronta Revisioni', 'Compare Revisions', 'Revisionen vergleichen', 'Comparer les révisions'],
            'PLM.DIFF_ADDED' => ['Aggiunto', 'Added', 'Hinzugefügt', 'Ajouté'],
            'PLM.DIFF_REMOVED' => ['Rimosso', 'Removed', 'Entfernt', 'Supprimé'],
            'PLM.DIFF_MODIFIED' => ['Quantità / Costo Modificato', 'Quantity / Cost Modified', 'Menge / Kosten geändert', 'Quantité / Coût modifié'],
            'PLM.DIFF_UNCHANGED' => ['Invariato', 'Unchanged', 'Unverändert', 'Inchangé'],
            'PLM.COST_BEFORE' => ['Costo Totale Rev. A', 'Total Cost Rev. A', 'Gesamtkosten Rev. A', 'Coût total Rév. A'],
            'PLM.COST_AFTER' => ['Costo Totale Rev. B', 'Total Cost Rev. B', 'Gesamtkosten Rev. B', 'Coût total Rév. B'],
            'PLM.COST_DELTA' => ['Variazione Costo (Delta)', 'Cost Delta', 'Kostendifferenz (Delta)', 'Écart de coût (Delta)'],

            'PLM.WHERE_USED_TITLE' => ['Analisi Impatto Where-Used Multilivello', 'Multi-Level Where-Used Impact Analyzer', 'Mehrstufige Verwendungs- & Auswirkungsanalyse', 'Analyse d’impact Where-Used multiniveau'],
            'PLM.WHERE_USED_SUBTITLE' => ['Implosione assiemi padri, Ordini di Acquisto aperti, Ordini di Produzione e Giacenze/Lotti.', 'Upward assembly implosion, open Purchase Orders, Production Orders, and Warehouse Stock/Lots.', 'Aufwärtsauflösung, offene Bestellungen, Fertigungsaufträge und Lagerbestände/Chargen.', 'Implosion ascendante, commandes d’achat ouvertes, ordres de fabrication et stocks/lots.'],
            'PLM.WHERE_USED_PARENTS' => ['Assiemi Padri & Prodotti Finiti', 'Parent Assemblies & Finished Goods', 'Übergeordnete Baugruppen & Fertigprodukte', 'Assemblages parents & Produits finis'],
            'PLM.WHERE_USED_OPEN_POS' => ['Ordini di Acquisto Aperti', 'Open Purchase Orders', 'Offene Bestellungen', 'Commandes d’achat ouvertes'],
            'PLM.WHERE_USED_OPEN_MOS' => ['Ordini di Produzione Aperti', 'Open Production Orders', 'Offene Fertigungsaufträge', 'Ordres de fabrication ouverts'],
            'PLM.WHERE_USED_STOCK_LOTS' => ['Giacenze di Magazzino & Lotti', 'Warehouse Stock & Lots', 'Lagerbestände & Chargen', 'Stocks d’entrepôt & Lots'],
            'PLM.ANALYZE_IMPACT' => ['Analizza Impatto', 'Analyze Impact', 'Auswirkung analysieren', 'Analyser l’impact'],
            'PLM.QUARANTINED' => ['In Quarantena', 'Quarantined', 'Unter Quarantäne', 'En quarantaine'],

            'PLM.VIEWER_TITLE' => ['Visualizzatore 3D STEP/STL & Tavole PDF/DXF', '3D STEP/STL & PDF/DXF Drawing Viewer', '3D-STEP/STL- & PDF/DXF-Zeichnungsviewer', 'Visionneuse 3D STEP/STL & Plans PDF/DXF'],
            'PLM.VIEWER_3D_TAB' => ['Modello 3D (STEP / STL)', '3D Model (STEP / STL)', '3D-Modell (STEP / STL)', 'Modèle 3D (STEP / STL)'],
            'PLM.VIEWER_PDF_TAB' => ['Tavola Costruttiva (PDF / DXF)', 'Engineering Drawing (PDF / DXF)', 'Konstruktionszeichnung (PDF / DXF)', 'Plan technique (PDF / DXF)'],
            'PLM.VIEWER_ROTATE' => ['Ruota', 'Rotate', 'Drehen', 'Pivoter'],
            'PLM.VIEWER_ZOOM_IN' => ['Ingrandisci', 'Zoom In', 'Vergrößern', 'Zoom avant'],
            'PLM.VIEWER_ZOOM_OUT' => ['Riduci', 'Zoom Out', 'Verkleinern', 'Zoom arrière'],
            'PLM.VIEWER_RESET' => ['Ripristina Vista', 'Reset View', 'Ansicht zurücksetzen', 'Réinitialiser la vue'],

            'PLM.COMPLIANCE_TITLE' => ['Fascicolo Tecnico CE / UKCA / OMacch & RoHS', 'CE / UKCA / OMacch Technical Dossier & RoHS', 'Technisches Dossier CE / UKCA / MaschV & RoHS', 'Dossier Technique CE / UKCA / OMacch & RoHS'],
            'PLM.TECH_FILE_REF' => ['Rif. Fascicolo Tecnico', 'Technical File Ref.', 'Ref. Technisches Dossier', 'Réf. Dossier Technique'],
            'PLM.ROHS_REACH' => ['Conforme RoHS / REACH', 'RoHS / REACH Compliant', 'RoHS- / REACH-konform', 'Conforme RoHS / REACH'],
            'PLM.CONFLICT_MINERALS' => ['Conflict Minerals (3TG)', 'Conflict Minerals (3TG)', 'Konfliktmineralien (3TG)', 'Minerais de conflit (3TG)'],
            'PLM.OMACCH_MRA' => ['OMacch (RS 819.14) / MRA CH-UE', 'OMacch (RS 819.14) / CH-EU MRA', 'MaschV (SR 819.14) / MRA CH-EU', 'OMacch (RS 819.14) / ARM CH-UE'],
            'PLM.ISO_AUDIT_READINESS' => ['Prontezza Audit ISO 9001 / IATF 16949 / ISO 13485', 'ISO 9001 / IATF 16949 / ISO 13485 Audit Readiness', 'Audit-Bereitschaft ISO 9001 / IATF 16949 / ISO 13485', 'Préparation audit ISO 9001 / IATF 16949 / ISO 13485'],
            'PLM.EVALUATE_COMPLIANCE' => ['Verifica Conformità', 'Evaluate Compliance', 'Konformität prüfen', 'Évaluer la conformité'],
            'PLM.COMPLIANT' => ['Conforme', 'Compliant', 'Konform', 'Conforme'],
            'PLM.NON_COMPLIANT' => ['Non Conforme', 'Non-Compliant', 'Nicht konform', 'Non conforme'],
            'PLM.MES_DRAWING_TITLE' => ['Tavola Tecnica Revisione Attiva (MES)', 'Active Revision Engineering Drawing (MES)', 'Aktive Revisionszeichnung (MES)', 'Plan technique révision active (MES)'],
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
