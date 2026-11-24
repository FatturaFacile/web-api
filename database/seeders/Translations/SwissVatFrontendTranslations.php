<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings for Swiss VAT (MWST / IVA / TVA) Mod 024 (#164) across it, en, de, fr.
 * Each entry is [it, en, de, fr]; the Italian dictionary serves it-CH and it-IT, German de-CH, French fr-CH.
 */
final class SwissVatFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.SWISS_VAT' => ['IVA Svizzera (MWST)', 'Swiss VAT (MWST)', 'Schweizer MwSt', 'TVA Suisse'],
            'NAV.SWISS_VAT_RETURNS' => ['Rendiconti AFC/ESTV', 'AFC/ESTV Returns', 'ESTV-Abrechnungen', 'Décomptes AFC'],
            'NAV.SWISS_VAT_CONFIG' => ['Regime e Aliquote CH', 'CH Regime & Rates', 'CH-Methode & Sätze', 'Régime & Taux CH'],
            'NAV.SWISS_VAT_BEZUGSTEUER' => ['Bezugsteuer (Estero)', 'Bezugsteuer (Foreign)', 'Bezugsteuer (Ausland)', 'Impôt acquisitions (Étr.)'],
            'NAV.SWISS_VAT_ADJUSTMENTS' => ['Rettifiche Imposta Prec.', 'Input Tax Adjustments', 'Vorsteuerkorrekturen', 'Corrections impôt préal.'],
            'PAGE_TITLE.SWISS_VAT' => ['IVA Svizzera (MWST / IVA / TVA) e SuisseTax XML', 'Swiss VAT (MWST / IVA / TVA) & SuisseTax XML', 'Schweizer MwSt & SuisseTax XML', 'TVA Suisse & SuisseTax XML'],

            'swissVat.title' => ['IVA Svizzera (MWST / IVA / TVA)', 'Swiss VAT (MWST / IVA / TVA)', 'Schweizer Mehrwertsteuer (MWST)', 'TVA Suisse (LTVA / AFC)'],
            'swissVat.subtitle' => ['Rendicontazione AFC/ESTV — Metodo Effettivo, Saldosteuersatz, Bezugsteuer e SuisseTax XML', 'AFC/ESTV Reporting — Effective Method, Saldosteuersatz, Bezugsteuer & SuisseTax XML', 'ESTV-Abrechnung — Effektiv, Saldosteuersatz, Bezugsteuer & SuisseTax XML', 'Décompte AFC — Méthode effective, TDFN, impôt sur les acquisitions & SuisseTax XML'],

            'swissVat.tab.returns' => ['Rendiconti & AFC', 'Returns & AFC', 'Abrechnungen & ESTV', 'Décomptes & AFC'],
            'swissVat.tab.config' => ['Regime & Aliquote', 'Regime & Rates', 'Methode & Sätze', 'Régime & Taux'],
            'swissVat.tab.bezugsteuer' => ['Bezugsteuer', 'Bezugsteuer', 'Bezugsteuer', 'Impôt acquisitions'],
            'swissVat.tab.adjustments' => ['Rettifiche LIVA', 'Input Adjustments', 'Vorsteuerkorrekturen', 'Corrections impôt'],

            'swissVat.method.effective' => ['Metodo Effettivo', 'Effective Method', 'Effektive Methode', 'Méthode effective'],
            'swissVat.method.saldosteuersatz' => ['Saldosteuersatz (Aliquota a Saldo)', 'Saldosteuersatz (Net Tax Rate)', 'Saldosteuersatzmethode', 'Taux de la dette fiscale nette'],
            'swissVat.basis.agreed' => ['Controprestazioni convenute (fatturato)', 'Agreed consideration (invoiced)', 'Vereinbartes Entgelt', 'Contre-prestations convenues'],
            'swissVat.basis.received' => ['Controprestazioni riscosse (incassato)', 'Received consideration (cash)', 'Vereinnahmtes Entgelt', 'Contre-prestations reçues'],
            'swissVat.freq.quarterly' => ['Trimestrale', 'Quarterly', 'Quartalsweise', 'Trimestriel'],
            'swissVat.freq.semiannual' => ['Semestrale', 'Semi-annual', 'Halbjährlich', 'Semestriel'],
            'swissVat.freq.monthly' => ['Mensile', 'Monthly', 'Monatlich', 'Mensuel'],

            'swissVat.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'swissVat.status.calculated' => ['Calcolato', 'Calculated', 'Berechnet', 'Calculé'],
            'swissVat.status.validated' => ['Validato', 'Validated', 'Validiert', 'Validé'],
            'swissVat.status.finalized' => ['Finalizzato', 'Finalized', 'Finalisiert', 'Finalisé'],
            'swissVat.status.submitted' => ['Inviato a SuisseTax', 'Submitted to SuisseTax', 'An SuisseTax übermittelt', 'Transmis à SuisseTax'],
            'swissVat.status.detected' => ['Rilevato auto', 'Auto-detected', 'Automatisch erkannt', 'Détecté auto'],
            'swissVat.status.confirmed' => ['Confermato', 'Confirmed', 'Bestätigt', 'Confirmé'],
            'swissVat.status.excluded' => ['Escluso', 'Excluded', 'Ausgeschlossen', 'Exclu'],
            'swissVat.status.declared' => ['Dichiarato', 'Declared', 'Deklariert', 'Déclaré'],
            'swissVat.status.approved' => ['Approvato', 'Approved', 'Genehmigt', 'Approuvé'],
            'swissVat.status.applied' => ['Applicato', 'Applied', 'Angewendet', 'Appliqué'],

            'swissVat.action.calculate' => ['Calcola periodo', 'Calculate period', 'Periode berechnen', 'Calculer la période'],
            'swissVat.action.recalculate' => ['Ricalcola', 'Recalculate', 'Neu berechnen', 'Recalculer'],
            'swissVat.action.validateXsd' => ['Valida XSD SuisseTax', 'Validate SuisseTax XSD', 'SuisseTax XSD prüfen', 'Valider XSD SuisseTax'],
            'swissVat.action.finalize' => ['Finalizza e blocca', 'Finalize & lock', 'Finalisieren & sperren', 'Finaliser & verrouiller'],
            'swissVat.action.exportXml' => ['Esporta XML SuisseTax', 'Export SuisseTax XML', 'SuisseTax XML exportieren', 'Exporter XML SuisseTax'],
            'swissVat.action.downloadXml' => ['Scarica XML', 'Download XML', 'XML herunterladen', 'Télécharger XML'],
            'swissVat.action.detectBezugsteuer' => ['Rileva servizi esteri', 'Detect foreign services', 'Auslandleistungen erkennen', 'Détecter services étrangers'],
            'swissVat.action.addBezugsteuer' => ['Nuova Bezugsteuer', 'New Bezugsteuer', 'Neue Bezugsteuer', 'Nouvel impôt acq.'],
            'swissVat.action.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'swissVat.action.exclude' => ['Escludi', 'Exclude', 'Ausschliessen', 'Exclure'],
            'swissVat.action.addAdjustment' => ['Nuova rettifica', 'New adjustment', 'Neue Korrektur', 'Nouvelle correction'],
            'swissVat.action.approve' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'swissVat.action.saveConfig' => ['Salva configurazione', 'Save configuration', 'Konfiguration speichern', 'Enregistrer la configuration'],
            'swissVat.action.openReconciliation' => ['Riconciliazione & XML', 'Reconciliation & XML', 'Abstimmung & XML', 'Rapprochement & XML'],

            'swissVat.field.uidMwst' => ['Numero UID-MWST', 'UID-MWST Number', 'UID-MWST-Nummer', 'Numéro IDE-TVA'],
            'swissVat.field.method' => ['Metodo di rendicontazione', 'Accounting method', 'Abrechnungsmethode', 'Méthode de décompte'],
            'swissVat.field.basis' => ['Principio di imposizione', 'Consideration basis', 'Abrechnungsart', 'Mode d\'imposition'],
            'swissVat.field.frequency' => ['Periodicità', 'Reporting frequency', 'Abrechnungsperiode', 'Périodicité'],
            'swissVat.field.sssPrimary' => ['Aliquota Saldosteuersatz primaria (%)', 'Primary Saldosteuersatz (%)', 'Primärer Saldosteuersatz (%)', 'Taux TDFN principal (%)'],
            'swissVat.field.sssSecondary' => ['Aliquota Saldosteuersatz secondaria (%)', 'Secondary Saldosteuersatz (%)', 'Sekundärer Saldosteuersatz (%)', 'Taux TDFN secondaire (%)'],
            'swissVat.field.rounding' => ['Arrotondamento CHF', 'CHF rounding', 'CHF-Rundung', 'Arrondi CHF'],
            'swissVat.field.year' => ['Anno fiscale', 'Fiscal year', 'Steuerjahr', 'Année fiscale'],
            'swissVat.field.period' => ['Periodo', 'Period', 'Periode', 'Période'],
            'swissVat.field.supplier' => ['Fornitore estero', 'Foreign supplier', 'Ausländischer Lieferant', 'Fournisseur étranger'],
            'swissVat.field.country' => ['Paese', 'Country', 'Land', 'Pays'],
            'swissVat.field.serviceCategory' => ['Categoria servizio', 'Service category', 'Leistungskategorie', 'Catégorie de service'],
            'swissVat.field.netChf' => ['Imponibile CHF', 'Net amount CHF', 'Nettobetrag CHF', 'Montant net CHF'],
            'swissVat.field.taxChf' => ['Imposta CHF', 'VAT amount CHF', 'Steuerbetrag CHF', 'Montant TVA CHF'],
            'swissVat.field.adjustmentType' => ['Tipo rettifica LIVA', 'LIVA adjustment type', 'MWSTG-Korrekturart', 'Type de correction LTVA'],
            'swissVat.field.afcBox' => ['Cifra AFC', 'AFC Box', 'ESTV-Ziffer', 'Chiffre AFC'],
            'swissVat.field.legalBasis' => ['Base legale', 'Legal basis', 'Rechtsgrundlage', 'Base légale'],

            'swissVat.adj.autoconsumo' => ['Autoconsumo (Art. 31 LIVA)', 'Self-consumption (Art. 31 LIVA)', 'Eigenverbrauch (Art. 31 MWSTG)', 'Prestation à soi-même (Art. 31 LTVA)'],
            'swissVat.adj.degrassazione' => ['Degrassazione (Art. 32 LIVA)', 'Subsequent deduction (Art. 32 LIVA)', 'Einlageentsteuerung (Art. 32 MWSTG)', 'Dégrèvement ultérieur (Art. 32 LTVA)'],
            'swissVat.adj.mixed_use_reduction' => ['Uso misto (Art. 30 LIVA)', 'Mixed-use reduction (Art. 30 LIVA)', 'Gemischte Verwendung (Art. 30 MWSTG)', 'Usage mixte (Art. 30 LTVA)'],
            'swissVat.adj.subsidy_reduction' => ['Riduzione sussidi (Art. 33 cpv. 2 LIVA)', 'Subsidy reduction (Art. 33(2) LIVA)', 'Subventionskürzung (Art. 33 Abs. 2 MWSTG)', 'Réduction subventions (Art. 33 al. 2 LTVA)'],

            'swissVat.quadratura.title' => ['Quadratura Contabile & Controlli AFC', 'Accounting Quadratura & AFC Checks', 'Buchhalterische Quadratur & ESTV-Prüfung', 'Équilibre comptable & contrôles AFC'],
            'swissVat.quadratura.passed' => ['Quadratura superata — pronto per finalizzazione ed esportazione SuisseTax', 'Quadratura passed — ready to finalize and export SuisseTax XML', 'Quadratur bestanden — bereit für Finalisierung und SuisseTax XML', 'Équilibre validé — prêt pour finalisation et export SuisseTax XML'],
            'swissVat.quadratura.failed' => ['Quadratura non superata — risolvere gli errori bloccanti prima dell\'esportazione', 'Quadratura failed — resolve blocking discrepancies before export', 'Quadratur fehlgeschlagen — blockierende Abweichungen vor Export beheben', 'Équilibre échoué — corriger les écarts bloquants avant l\'export'],
            'swissVat.xsd.valid' => ['Schema XSD SuisseTax eCH-0217 v2.0 conforme', 'SuisseTax eCH-0217 v2.0 XSD valid', 'SuisseTax eCH-0217 v2.0 XSD konform', 'Schéma XSD SuisseTax eCH-0217 v2.0 conforme'],
            'swissVat.xsd.invalid' => ['Errore di validazione XSD / regole SuisseTax', 'SuisseTax XSD / business validation failed', 'SuisseTax XSD / Regelprüfung fehlgeschlagen', 'Validation XSD / règles SuisseTax échouée'],
            'swissVat.exports.history' => ['Storico esportazioni SuisseTax XML', 'SuisseTax XML export history', 'SuisseTax XML-Exportverlauf', 'Historique des exports XML SuisseTax'],

            'swissVat.kpi.turnover' => ['Cifra d\'affari (200)', 'Turnover (200)', 'Gesamtumsatz (200)', 'Chiffre d\'affaires (200)'],
            'swissVat.kpi.outputTax' => ['Imposta dovuta (399)', 'Output tax (399)', 'Geschuldete Steuer (399)', 'Impôt dû (399)'],
            'swissVat.kpi.inputTax' => ['Imposta precedente (479)', 'Input tax (479)', 'Vorsteuer (479)', 'Impôt préalable (479)'],
            'swissVat.kpi.payable' => ['Saldo AFC (500/510)', 'Net AFC Balance (500/510)', 'ESTV-Saldo (500/510)', 'Solde AFC (500/510)'],
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

        $out = [];
        foreach (self::definitions() as $key => $row) {
            $out[$key] = $row[$index];
        }

        return $out;
    }
}
