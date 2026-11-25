<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings for Mod 232 (Issue #478): Customer Returns (Reverse Logistics), Advance Replacements (Cross-Shipping),
 * Refurbished / B-Stock Grading, and Credit Note VAT Time-Limit Control (Art. 26 DPR 633/72 & Art. 41 LIVA).
 * Each entry is [it, en, de, fr]. Legal acronyms (RMA, B2B, B2C, DOA, DDT, TD04, N2.2, DPR 633/72, LIVA, SLA, SKU) stay untranslated.
 */
final class CustomerReturnsReverseLogisticsFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.RETURNS_PORTAL' => ['Portale Resi Self-Service', 'Self-Service Returns Portal', 'Self-Service-Retourenportal', 'Portail Retours Self-Service'],
            'NAV.RETURNS_ADVANCE_REPLACEMENTS' => ['Sostituzioni Anticipate', 'Advance Replacements', 'Vorab-Ersatzlieferungen', 'Remplacements Anticipés'],
            'NAV.RETURNS_GRADING_BENCH' => ['Stazione Grading & B-Stock', 'Grading & B-Stock Bench', 'Prüfstation & B-Ware', 'Poste Grading & B-Stock'],
            'NAV.RETURNS_CREDIT_NOTES' => ['Controllo Note di Credito TD04', 'Credit Note TD04 Control', 'Gutschrift-Kontrolle TD04', 'Contrôle Avoirs TD04'],

            'PAGE_TITLE.RETURNS_PORTAL' => ['Portale Resi Cliente (RMA)', 'Customer Returns Portal (RMA)', 'Kunden-Retourenportal (RMA)', 'Portail Retours Client (RMA)'],
            'PAGE_TITLE.RETURNS_ADVANCE_REPLACEMENTS' => ['Sostituzioni Anticipate (Cross-Shipping)', 'Advance Replacements (Cross-Shipping)', 'Vorab-Ersatzlieferungen (Cross-Shipping)', 'Remplacements Anticipés (Cross-Shipping)'],
            'PAGE_TITLE.RETURNS_GRADING_BENCH' => ['Perizia Resi, Grading & B-Stock', 'Return Grading & Refurbished B-Stock', 'Retouren-Grading & B-Ware', 'Grading Retours & B-Stock Reconditionné'],
            'PAGE_TITLE.RETURNS_CREDIT_NOTES' => ['Controllo Fiscale Note di Credito (Art. 26 / LIVA)', 'Credit Note Fiscal Control (Art. 26 / LIVA)', 'Steuerkontrolle Gutschriften (Art. 26 / MWSTG)', 'Contrôle Fiscal Avoirs (Art. 26 / LTVA)'],

            'RETURNS_RL.PORTAL_TITLE' => ['Richiesta Reso Self-Service (B2B / B2C)', 'Self-Service Return Request (B2B / B2C)', 'Self-Service-Retourenantrag (B2B / B2C)', 'Demande de Retour Self-Service (B2B / B2C)'],
            'RETURNS_RL.PORTAL_SUBTITLE' => ['Verifica idoneità su fattura o DDT, calcolo restocking fee, caricamento foto e stampa etichetta RMA', 'Check invoice or DDT eligibility, restocking fee, upload photos and print RMA label', 'Prüfung auf Rechnung oder Lieferschein, Wiedereinlagerungsgebühr, Fotos und RMA-Etikett', 'Vérifier l\'éligibilité sur facture ou BL, frais de restockage, photos et étiquette RMA'],
            'RETURNS_RL.CHANNEL' => ['Canale', 'Channel', 'Kanal', 'Canal'],
            'RETURNS_RL.CHANNEL_PORTAL_B2B' => ['Portale B2B', 'B2B Portal', 'B2B-Portal', 'Portail B2B'],
            'RETURNS_RL.CHANNEL_PORTAL_B2C' => ['Portale B2C', 'B2C Portal', 'B2C-Portal', 'Portail B2C'],
            'RETURNS_RL.CHANNEL_BACKOFFICE' => ['Back-office', 'Back-office', 'Backoffice', 'Back-office'],
            'RETURNS_RL.CHECK_ELIGIBILITY' => ['Verifica Idoneità & Fee', 'Check Eligibility & Fee', 'Anspruch & Gebühr prüfen', 'Vérifier l\'éligibilité & frais'],
            'RETURNS_RL.ELIGIBLE_YES' => ['Idoneo al reso', 'Eligible for return', 'Rückgabeberechtigt', 'Éligible au retour'],
            'RETURNS_RL.ELIGIBLE_NO' => ['Finestra di reso scaduta', 'Return window expired', 'Rückgabefrist abgelaufen', 'Délai de retour expiré'],
            'RETURNS_RL.DAYS_SINCE_PURCHASE' => ['Giorni dall\'acquisto', 'Days since purchase', 'Tage seit Kauf', 'Jours depuis l\'achat'],
            'RETURNS_RL.RESTOCKING_FEE' => ['Commissione di riassortimento (Restocking Fee)', 'Restocking Fee', 'Wiedereinlagerungsgebühr', 'Frais de restockage'],
            'RETURNS_RL.GROSS_AMOUNT' => ['Importo lordo reso', 'Gross return amount', 'Brutto-Retourenbetrag', 'Montant brut du retour'],
            'RETURNS_RL.NET_REFUNDABLE' => ['Importo netto rimborsabile', 'Net refundable amount', 'Netto-Erstattungsbetrag', 'Montant net remboursable'],
            'RETURNS_RL.PHOTO_EVIDENCE' => ['Foto prodotto / pacco (URL separati da virgola)', 'Product / package photos (comma-separated URLs)', 'Produkt- / Paketfotos (kommagetrennte URLs)', 'Photos produit / colis (URL séparées par virgule)'],
            'RETURNS_RL.SUBMIT_RMA' => ['Invia Richiesta RMA', 'Submit RMA Request', 'RMA-Antrag absenden', 'Soumettre la demande RMA'],
            'RETURNS_RL.RMA_CREATED' => ['Richiesta RMA creata con codice a barre', 'RMA request created with barcode', 'RMA-Antrag mit Barcode erstellt', 'Demande RMA créée avec code-barres'],
            'RETURNS_RL.SHIPPING_LABEL' => ['Etichetta di Spedizione Reso & Barcode RMA', 'Return Shipping Label & RMA Barcode', 'Retouren-Versandetikett & RMA-Barcode', 'Étiquette d\'Expédition Retour & Code-barres RMA'],
            'RETURNS_RL.PRINT_LABEL' => ['Stampa Etichetta RMA', 'Print RMA Label', 'RMA-Etikett drucken', 'Imprimer l\'étiquette RMA'],
            'RETURNS_RL.BARCODE' => ['Codice a barre RMA', 'RMA Barcode', 'RMA-Barcode', 'Code-barres RMA'],

            'RETURNS_RL.XSHP_TITLE' => ['Sostituzioni Anticipate (Cross-Shipping)', 'Advance Replacements (Cross-Shipping)', 'Vorab-Ersatzlieferungen (Cross-Shipping)', 'Remplacements Anticipés (Cross-Shipping)'],
            'RETURNS_RL.XSHP_SUBTITLE' => ['Spedizione immediata unità sostitutiva con finestra SLA e addebito automatico su mancato rientro', 'Immediate replacement shipment with SLA window and automated overdue billing', 'Sofortige Ersatzlieferung mit SLA-Frist und automatischer Nachbelastung', 'Expédition immédiate de remplacement avec délai SLA et facturation de retard'],
            'RETURNS_RL.NEW_XSHP' => ['Nuova Sostituzione Anticipata', 'New Advance Replacement', 'Neue Vorab-Ersatzlieferung', 'Nouveau Remplacement Anticipé'],
            'RETURNS_RL.SCAN_OVERDUE' => ['Scansiona & Fattura Scaduti', 'Scan & Bill Overdue', 'Überfällige prüfen & berechnen', 'Scanner & Facturer les retards'],
            'RETURNS_RL.UNIT_TYPE' => ['Tipo Unità Sostitutiva', 'Replacement Unit Type', 'Ersatzgerätetyp', 'Type d\'Unité de Remplacement'],
            'RETURNS_RL.UNIT_NEW' => ['Unità Nuova', 'New Unit', 'Neugerät', 'Unité Neuve'],
            'RETURNS_RL.UNIT_REFURBISHED' => ['Unità Ricondizionata (B-Stock)', 'Refurbished Unit (B-Stock)', 'Aufbereitetes Gerät (B-Ware)', 'Unité Reconditionnée (B-Stock)'],
            'RETURNS_RL.SLA_DAYS' => ['Finestra SLA rientro (giorni)', 'Return SLA window (days)', 'Rückgabe-SLA-Frist (Tage)', 'Délai SLA de retour (jours)'],
            'RETURNS_RL.RETURN_DUE_ON' => ['Scadenza rientro difettoso', 'Defective return due on', 'Rückgabefrist Defektgerät', 'Échéance retour défectueux'],
            'RETURNS_RL.PENALTY_AMOUNT' => ['Penale mancato rientro', 'Overdue penalty amount', 'Strafbetrag bei Nichtrückgabe', 'Pénalité de non-retour'],
            'RETURNS_RL.RECEIVE_DEFECTIVE' => ['Registra Rientro Difettoso', 'Receive Defective Unit', 'Defektgerät erhalten', 'Réceptionner l\'unité défectueuse'],
            'RETURNS_RL.BILL_OVERDUE' => ['Emetti Fattura Penale', 'Bill Overdue Penalty', 'Strafrechnung erstellen', 'Facturer la pénalité'],
            'RETURNS_RL.XSHP_STATUS_PENDING_DEFECTIVE_RETURN' => ['In attesa rientro difettoso', 'Pending Defective Return', 'Wartet auf Defektrückgabe', 'En attente de retour défectueux'],
            'RETURNS_RL.XSHP_STATUS_DEFECTIVE_RECEIVED' => ['Difettoso rientrato', 'Defective Received', 'Defektgerät eingegangen', 'Défectueux réceptionné'],
            'RETURNS_RL.XSHP_STATUS_OVERDUE_BILLED' => ['Scaduto — Penale fatturata', 'Overdue — Penalty Billed', 'Überfällig — Berechnet', 'En retard — Pénalité facturée'],
            'RETURNS_RL.XSHP_STATUS_CANCELLED' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],

            'RETURNS_RL.GRADING_TITLE' => ['Stazione Perizia Resi, Grading & Ricondizionati (B-Stock)', 'Return Inspection, Grading & Refurbished B-Stock', 'Retourenprüfung, Grading & B-Ware', 'Inspection Retours, Grading & B-Stock Reconditionné'],
            'RETURNS_RL.GRADING_SUBTITLE' => ['Checklist con foto obbligatorie, grading A/B/C/Scarto, svalutazione costi e conversione SKU B-Stock', 'Checklist with mandatory photos, A/B/C/Scrap grading, cost devaluation and B-Stock SKU conversion', 'Checkliste mit Pflichtfotos, A/B/C/Schrott-Grading, Abwertung und B-Ware-SKU-Erstellung', 'Check-list avec photos obligatoires, grading A/B/C/Rebut, dépréciation et conversion SKU B-Stock'],
            'RETURNS_RL.NEW_GRADING' => ['Nuova Perizia & Grading', 'New Inspection & Grading', 'Neue Prüfung & Grading', 'Nouvelle Inspection & Grading'],
            'RETURNS_RL.GRADE' => ['Grado', 'Grade', 'Grad', 'Grade'],
            'RETURNS_RL.GRADE_A' => ['Grado A — Intatto (Stock Primario)', 'Grade A — Intact (Primary Stock)', 'Grad A — Intakt (Primärbestand)', 'Grade A — Intact (Stock Primaire)'],
            'RETURNS_RL.GRADE_B' => ['Grado B — Scatola aperta / Lieve usura', 'Grade B — Open box / Minor wear', 'Grad B — Geöffnet / Leichte Spuren', 'Grade B — Boîte ouverte / Légère usure'],
            'RETURNS_RL.GRADE_C' => ['Grado C — Ricondizionato / Usura visibile', 'Grade C — Refurbished / Visible wear', 'Grad C — Aufbereitet / Sichtbare Spuren', 'Grade C — Reconditionné / Usure visible'],
            'RETURNS_RL.GRADE_SCRAP_OR_SUPPLIER' => ['Scarto / Reso Fornitore', 'Scrap / Supplier Return', 'Schrott / Lieferantenretoure', 'Rebut / Retour Fournisseur'],
            'RETURNS_RL.DEVALUATION_PERCENT' => ['Svalutazione (%)', 'Devaluation (%)', 'Abwertung (%)', 'Dépréciation (%)'],
            'RETURNS_RL.REVALUED_COST' => ['Costo rivalutato', 'Revalued cost', 'Neubewertete Kosten', 'Coût réévalué'],
            'RETURNS_RL.REFURBISHED_SKU' => ['SKU B-Stock / Ricondizionato', 'B-Stock / Refurbished SKU', 'B-Ware / Aufbereitete SKU', 'SKU B-Stock / Reconditionné'],
            'RETURNS_RL.WORK_ORDER_STATUS' => ['Stato Ordine Ricondizionamento', 'Refurbishment Work Order', 'Aufbereitungsauftrag', 'Ordre de Reconditionnement'],
            'RETURNS_RL.COMPLETE_WORK_ORDER' => ['Completa Ricondizionamento', 'Complete Reconditioning', 'Aufbereitung abschließen', 'Terminer le reconditionnement'],
            'RETURNS_RL.CHECKLIST_PACKAGE_INTACT' => ['Imballo integro', 'Package intact', 'Verpackung intakt', 'Emballage intact'],
            'RETURNS_RL.CHECKLIST_ACCESSORIES_COMPLETE' => ['Accessori completi', 'Accessories complete', 'Zubehör vollständig', 'Accessoires complets'],
            'RETURNS_RL.CHECKLIST_FUNCTIONAL_TEST' => ['Test funzionale superato', 'Functional test passed', 'Funktionstest bestanden', 'Test fonctionnel réussi'],
            'RETURNS_RL.MANDATORY_PHOTOS_HINT' => ['Almeno una foto è obbligatoria per registrare la perizia.', 'At least one photo is mandatory to record the grading.', 'Mindestens ein Foto ist für die Prüfung zwingend erforderlich.', 'Au moins une photo est obligatoire pour enregistrer le grading.'],

            'RETURNS_RL.CN_TITLE' => ['Controllo Fiscale Nota di Credito (TD04 — Art. 26 DPR 633/72 & Art. 41 LIVA)', 'Credit Note Fiscal Control (TD04 — Art. 26 DPR 633/72 & Art. 41 LIVA)', 'Steuerliche Gutschriftskontrolle (TD04 — Art. 26 DPR 633/72 & Art. 41 MWSTG)', 'Contrôle Fiscal Avoir (TD04 — Art. 26 DPR 633/72 & Art. 41 LTVA)'],
            'RETURNS_RL.CN_SUBTITLE' => ['Verifica automatica termine di 1 anno su fattura originaria, proposta Natura N2.2 e tracciabilità LIVA', 'Automatic 1-year check on original invoice, N2.2 nature proposal and LIVA traceability', 'Automatische 1-Jahres-Prüfung der Originalrechnung, Code N2.2 und MWSTG-Nachverfolgung', 'Vérification automatique du délai d\'1 an sur facture d\'origine, nature N2.2 et traçabilité LTVA'],
            'RETURNS_RL.RUN_FISCAL_CHECK' => ['Esegui Controllo Fiscale', 'Run Fiscal Check', 'Steuerprüfung ausführen', 'Exécuter le contrôle fiscal'],
            'RETURNS_RL.ISSUE_CREDIT_NOTE' => ['Emetti Nota di Credito (TD04)', 'Issue Credit Note (TD04)', 'Gutschrift ausstellen (TD04)', 'Émettre l\'avoir (TD04)'],
            'RETURNS_RL.APPLY_N2_2' => ['Emetti per solo imponibile fuori campo IVA (Natura N2.2)', 'Issue for taxable base only without VAT recovery (Nature N2.2)', 'Nur über Bemessungsgrundlage ohne MWST ausstellen (Code N2.2)', 'Émettre sur base imposable seule sans récupération TVA (Nature N2.2)'],
            'RETURNS_RL.ORIGINAL_INVOICE_DATE' => ['Data fattura originaria', 'Original invoice date', 'Datum der Originalrechnung', 'Date de la facture d\'origine'],
            'RETURNS_RL.DAYS_ELAPSED' => ['Giorni trascorsi', 'Days elapsed', 'Vergangene Tage', 'Jours écoulés'],
            'RETURNS_RL.VAT_RECOVERABLE' => ['IVA recuperabile', 'VAT recoverable', 'MWST abzugsfähig', 'TVA récupérable'],
            'RETURNS_RL.CN_ISSUED_SUCCESS' => ['Nota di Credito emessa con successo', 'Credit Note issued successfully', 'Gutschrift erfolgreich ausgestellt', 'Avoir émis avec succès'],
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
