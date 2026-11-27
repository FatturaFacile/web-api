<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the foreign VAT refund module (Mod 227, Issue #473): the dashboard with the threshold and deadline monitor, the foreign VAT ledger,
 * the claims (dossiers) with the wizard, the detail and the reconciliation, the matrix of the countries, the fiscal representative mandates, the
 * inline split badge of the invoice and expense screens, the notifications and the codes the API reports (foreignVat.error.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH. Placeholders are {name}.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI), the names of the procedures (ESTV, IVA 79, XIII) and the form numbers stay untranslated.
 */
final class ForeignVatRefundFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::countries(),
            self::categories(),
            self::enumerations(),
            self::errors(),
            self::fields(),
            self::actions(),
            self::sections(),
            self::messages(),
            self::notifications(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.FOREIGN_VAT_REFUND' => ['Rimborso IVA estera', 'Foreign VAT refund', 'Rückerstattung ausländischer MWST', 'Remboursement de TVA étrangère'],
            'NAV.FOREIGN_VAT_DASHBOARD' => ['Cruscotto soglie e scadenze', 'Thresholds and deadlines', 'Schwellen und Fristen', 'Seuils et échéances'],
            'NAV.FOREIGN_VAT_LEDGER' => ['Registro IVA estera', 'Foreign VAT ledger', 'Register ausländische MWST', 'Registre de TVA étrangère'],
            'NAV.FOREIGN_VAT_CLAIMS' => ['Istanze di rimborso', 'Refund claims', 'Rückerstattungsanträge', 'Demandes de remboursement'],
            'NAV.FOREIGN_VAT_CONFIG' => ['Regole Paesi e rappresentanti fiscali', 'Country rules and fiscal representatives', 'Länderregeln und Steuervertreter', 'Règles par pays et représentants fiscaux'],
            'PAGE_TITLE.FOREIGN_VAT_DASHBOARD' => ['Rimborso IVA estera: soglie e scadenze perentorie', 'Foreign VAT refund: thresholds and peremptory deadlines', 'Rückerstattung ausländischer MWST: Schwellen und Verwirkungsfristen', 'Remboursement de TVA étrangère : seuils et délais péremptoires'],
            'PAGE_TITLE.FOREIGN_VAT_LEDGER' => ['Registro IVA estera', 'Foreign VAT ledger', 'Register der ausländischen MWST', 'Registre de la TVA étrangère'],
            'PAGE_TITLE.FOREIGN_VAT_CLAIMS' => ['Istanze di rimborso IVA estera', 'Foreign VAT refund claims', 'Anträge auf Rückerstattung ausländischer MWST', 'Demandes de remboursement de TVA étrangère'],
            'PAGE_TITLE.FOREIGN_VAT_CONFIG' => ['Regole Paesi e rappresentanti fiscali', 'Country rules and fiscal representatives', 'Länderregeln und Steuervertreter', 'Règles par pays et représentants fiscaux'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function countries(): array
    {
        return [
            'foreignVat.country.CH' => ['Svizzera', 'Switzerland', 'Schweiz', 'Suisse'],
            'foreignVat.country.IT' => ['Italia', 'Italy', 'Italien', 'Italie'],
            'foreignVat.country.DE' => ['Germania', 'Germany', 'Deutschland', 'Allemagne'],
            'foreignVat.country.FR' => ['Francia', 'France', 'Frankreich', 'France'],
            'foreignVat.country.AT' => ['Austria', 'Austria', 'Österreich', 'Autriche'],
            'foreignVat.country.ES' => ['Spagna', 'Spain', 'Spanien', 'Espagne'],
            'foreignVat.country.NL' => ['Paesi Bassi', 'Netherlands', 'Niederlande', 'Pays-Bas'],
            'foreignVat.country.BE' => ['Belgio', 'Belgium', 'Belgien', 'Belgique'],
            'foreignVat.country.PT' => ['Portogallo', 'Portugal', 'Portugal', 'Portugal'],
            'foreignVat.country.LU' => ['Lussemburgo', 'Luxembourg', 'Luxemburg', 'Luxembourg'],
            'foreignVat.country.IE' => ['Irlanda', 'Ireland', 'Irland', 'Irlande'],
            'foreignVat.country.FI' => ['Finlandia', 'Finland', 'Finnland', 'Finlande'],
            'foreignVat.country.GR' => ['Grecia', 'Greece', 'Griechenland', 'Grèce'],
            'foreignVat.country.SI' => ['Slovenia', 'Slovenia', 'Slowenien', 'Slovénie'],
            'foreignVat.country.SK' => ['Slovacchia', 'Slovakia', 'Slowakei', 'Slovaquie'],
            'foreignVat.country.EE' => ['Estonia', 'Estonia', 'Estland', 'Estonie'],
            'foreignVat.country.LV' => ['Lettonia', 'Latvia', 'Lettland', 'Lettonie'],
            'foreignVat.country.LT' => ['Lituania', 'Lithuania', 'Litauen', 'Lituanie'],
            'foreignVat.country.MT' => ['Malta', 'Malta', 'Malta', 'Malte'],
            'foreignVat.country.CY' => ['Cipro', 'Cyprus', 'Zypern', 'Chypre'],
            'foreignVat.country.HR' => ['Croazia', 'Croatia', 'Kroatien', 'Croatie'],
            'foreignVat.country.DK' => ['Danimarca', 'Denmark', 'Dänemark', 'Danemark'],
            'foreignVat.country.SE' => ['Svezia', 'Sweden', 'Schweden', 'Suède'],
            'foreignVat.country.PL' => ['Polonia', 'Poland', 'Polen', 'Pologne'],
            'foreignVat.country.CZ' => ['Repubblica Ceca', 'Czech Republic', 'Tschechien', 'Tchéquie'],
            'foreignVat.country.HU' => ['Ungheria', 'Hungary', 'Ungarn', 'Hongrie'],
            'foreignVat.country.RO' => ['Romania', 'Romania', 'Rumänien', 'Roumanie'],
            'foreignVat.country.BG' => ['Bulgaria', 'Bulgaria', 'Bulgarien', 'Bulgarie'],
            'foreignVat.country.GB' => ['Regno Unito', 'United Kingdom', 'Vereinigtes Königreich', 'Royaume-Uni'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function categories(): array
    {
        return [
            'foreignVat.category.1' => ['Carburante', 'Fuel', 'Treibstoff', 'Carburant'],
            'foreignVat.category.2' => ['Noleggio di mezzi di trasporto', 'Hiring of means of transport', 'Miete von Transportmitteln', 'Location de moyens de transport'],
            'foreignVat.category.3' => ['Spese relative ai mezzi di trasporto', 'Expenditure on means of transport', 'Ausgaben für Transportmittel', 'Dépenses relatives aux moyens de transport'],
            'foreignVat.category.4' => ['Pedaggi stradali e tasse di circolazione', 'Road tolls and road user charges', 'Straßenmaut und Straßenbenutzungsgebühren', 'Péages et taxes d’usage des routes'],
            'foreignVat.category.5' => ['Spese di viaggio', 'Travel expenses', 'Reisekosten', 'Frais de voyage'],
            'foreignVat.category.6' => ['Alloggio', 'Accommodation', 'Unterkunft', 'Hébergement'],
            'foreignVat.category.7' => ['Alimenti, bevande e ristorazione', 'Food, drink and restaurant services', 'Speisen, Getränke und Restaurantleistungen', 'Aliments, boissons et restauration'],
            'foreignVat.category.8' => ['Ingressi a fiere ed esposizioni', 'Admissions to fairs and exhibitions', 'Eintritte zu Messen und Ausstellungen', 'Entrées à des foires et expositions'],
            'foreignVat.category.9' => ['Beni di lusso, divertimento e rappresentanza', 'Luxuries, amusement and entertainment', 'Luxus, Unterhaltung und Repräsentation', 'Luxe, divertissement et représentation'],
            'foreignVat.category.10' => ['Altro', 'Other', 'Sonstiges', 'Autre'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            // Procedures
            'foreignVat.regime.EU_DIR_2008_9_CE' => ['Portale elettronico UE (Dir. 2008/9/CE)', 'EU electronic portal (Dir. 2008/9/EC)', 'Elektronisches EU-Portal (RL 2008/9/EG)', 'Portail électronique UE (Dir. 2008/9/CE)'],
            'foreignVat.regime.CH_ESTV_RECIPROCITY' => ['Reciprocità svizzera ESTV (moduli 1222/1223)', 'Swiss ESTV reciprocity (forms 1222/1223)', 'Schweizer Gegenseitigkeit ESTV (Formulare 1222/1223)', 'Réciprocité suisse AFC (formulaires 1222/1223)'],
            'foreignVat.regime.IT_ART_38_TER' => ['Art. 38-ter DPR 633/72 (Modello IVA 79, Pescara)', 'Art. 38-ter DPR 633/72 (Modello IVA 79, Pescara)', 'Art. 38-ter DPR 633/72 (Modello IVA 79, Pescara)', 'Art. 38-ter DPR 633/72 (Modello IVA 79, Pescara)'],
            'foreignVat.regime.XIII_DIR_86_560_CEE' => ['XIII Direttiva 86/560/CEE', 'Thirteenth Directive 86/560/EEC', 'Dreizehnte Richtlinie 86/560/EWG', 'Treizième directive 86/560/CEE'],
            // Ledger line status
            'foreignVat.ledgerStatus.PENDING_REVIEW' => ['Da verificare', 'Pending review', 'Zu prüfen', 'À vérifier'],
            'foreignVat.ledgerStatus.ELIGIBLE_ACCUMULATING' => ['Rimborsabile, in accumulo', 'Eligible, accumulating', 'Berechtigt, wird angesammelt', 'Remboursable, en cumul'],
            'foreignVat.ledgerStatus.INCLUDED_IN_CLAIM' => ['Inclusa in un’istanza', 'Included in a claim', 'In einem Antrag enthalten', 'Incluse dans une demande'],
            'foreignVat.ledgerStatus.REFUNDED' => ['Rimborsata', 'Refunded', 'Rückerstattet', 'Remboursée'],
            'foreignVat.ledgerStatus.PARTIALLY_REJECTED' => ['Parzialmente respinta', 'Partially rejected', 'Teilweise abgelehnt', 'Partiellement refusée'],
            'foreignVat.ledgerStatus.NON_RECOVERABLE_EXPENSED' => ['Non rimborsabile, a costo', 'Non-recoverable, expensed', 'Nicht rückforderbar, als Aufwand', 'Non récupérable, en charge'],
            // Claim status
            'foreignVat.claimStatus.DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'foreignVat.claimStatus.READY_TO_SUBMIT' => ['Pronta per l’invio', 'Ready to submit', 'Versandbereit', 'Prête à l’envoi'],
            'foreignVat.claimStatus.SUBMITTED' => ['Inviata', 'Submitted', 'Eingereicht', 'Envoyée'],
            'foreignVat.claimStatus.ADDITIONAL_INFO_REQUESTED' => ['Integrazione documentale richiesta', 'Additional information requested', 'Zusätzliche Unterlagen angefordert', 'Pièces complémentaires demandées'],
            'foreignVat.claimStatus.PARTIALLY_APPROVED' => ['Accolta parzialmente', 'Partially approved', 'Teilweise bewilligt', 'Partiellement acceptée'],
            'foreignVat.claimStatus.APPROVED' => ['Accolta', 'Approved', 'Bewilligt', 'Acceptée'],
            'foreignVat.claimStatus.REFUNDED' => ['Rimborsata', 'Refunded', 'Rückerstattet', 'Remboursée'],
            'foreignVat.claimStatus.REJECTED' => ['Respinta', 'Rejected', 'Abgelehnt', 'Refusée'],
            // Timeline steps of a claim
            'foreignVat.step.DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'foreignVat.step.READY_TO_SUBMIT' => ['Pronta', 'Ready', 'Bereit', 'Prête'],
            'foreignVat.step.SUBMITTED' => ['Inviata', 'Submitted', 'Eingereicht', 'Envoyée'],
            'foreignVat.step.DECIDED' => ['Decisione', 'Decision', 'Entscheid', 'Décision'],
            'foreignVat.step.REFUNDED' => ['Rimborsata', 'Refunded', 'Rückerstattet', 'Remboursée'],
            'foreignVat.periodType.ANNUAL' => ['Annuale', 'Annual', 'Jährlich', 'Annuelle'],
            'foreignVat.periodType.QUARTERLY' => ['Trimestrale', 'Quarterly', 'Vierteljährlich', 'Trimestrielle'],
            'foreignVat.source.PASSIVE_INVOICE' => ['Fattura passiva', 'Supplier invoice', 'Eingangsrechnung', 'Facture fournisseur'],
            'foreignVat.source.EXPENSE_REPORT' => ['Nota spese', 'Expense report', 'Spesenabrechnung', 'Note de frais'],
            'foreignVat.source.MANUAL' => ['Inserimento manuale', 'Manual entry', 'Manuelle Erfassung', 'Saisie manuelle'],
            'foreignVat.vatBookedOn.input_vat' => ['Conto IVA a credito', 'Input VAT account', 'Vorsteuerkonto', 'Compte de TVA déductible'],
            'foreignVat.vatBookedOn.expense' => ['Conto di costo', 'Expense account', 'Aufwandkonto', 'Compte de charge'],
            'foreignVat.eligibility.THRESHOLD_MET' => ['Soglia raggiunta', 'Threshold met', 'Schwelle erreicht', 'Seuil atteint'],
            'foreignVat.eligibility.BELOW_THRESHOLD' => ['Sotto soglia', 'Below threshold', 'Unter der Schwelle', 'Sous le seuil'],
            'foreignVat.reciprocity.yes' => ['Reciprocità', 'Reciprocity', 'Gegenseitigkeit', 'Réciprocité'],
            'foreignVat.reciprocity.no' => ['Nessuna reciprocità', 'No reciprocity', 'Keine Gegenseitigkeit', 'Pas de réciprocité'],
            'foreignVat.mandateStatus.NOT_REQUIRED' => ['Non richiesto', 'Not required', 'Nicht erforderlich', 'Non requis'],
            'foreignVat.mandateStatus.MISSING' => ['Rappresentante fiscale mancante', 'Fiscal representative missing', 'Steuervertreter fehlt', 'Représentant fiscal manquant'],
            'foreignVat.mandateStatus.CERTIFICATE_MISSING' => ['Attestazione di soggettività passiva mancante', 'Certificate of taxable status missing', 'Bescheinigung der Steuerpflicht fehlt', 'Attestation d’assujettissement manquante'],
            'foreignVat.mandateStatus.CERTIFICATE_EXPIRED' => ['Attestazione scaduta', 'Certificate expired', 'Bescheinigung abgelaufen', 'Attestation expirée'],
            'foreignVat.mandateStatus.CERTIFICATE_EXPIRING' => ['Attestazione in scadenza', 'Certificate expiring', 'Bescheinigung läuft ab', 'Attestation bientôt expirée'],
            'foreignVat.mandateStatus.VALID' => ['Valido', 'Valid', 'Gültig', 'Valide'],
            'foreignVat.mandate.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'foreignVat.mandate.inactive' => ['Non attivo', 'Inactive', 'Inaktiv', 'Inactif'],
            // Priority alert banners of the dashboard
            'foreignVat.alert.60' => ['{country} {year}: mancano {days} giorni al termine perentorio del {deadline}. IVA recuperabile non ancora richiesta: {amount} {currency}.', '{country} {year}: {days} days to the peremptory deadline of {deadline}. Recoverable VAT not yet claimed: {amount} {currency}.', '{country} {year}: noch {days} Tage bis zur Verwirkungsfrist vom {deadline}. Noch nicht beantragte rückforderbare MWST: {amount} {currency}.', '{country} {year} : {days} jours avant le délai péremptoire du {deadline}. TVA récupérable pas encore demandée : {amount} {currency}.'],
            'foreignVat.alert.30' => ['Attenzione, {country} {year}: solo {days} giorni al termine perentorio del {deadline}. IVA da richiedere: {amount} {currency}.', 'Warning, {country} {year}: only {days} days to the peremptory deadline of {deadline}. VAT to claim: {amount} {currency}.', 'Achtung, {country} {year}: nur noch {days} Tage bis zur Verwirkungsfrist vom {deadline}. Zu beantragende MWST: {amount} {currency}.', 'Attention, {country} {year} : seulement {days} jours avant le délai péremptoire du {deadline}. TVA à demander : {amount} {currency}.'],
            'foreignVat.alert.15' => ['Urgente, {country} {year}: {days} giorni al termine perentorio del {deadline}. Oltre quella data il diritto al rimborso decade: {amount} {currency}.', 'Urgent, {country} {year}: {days} days to the peremptory deadline of {deadline}. After that date the right to the refund lapses: {amount} {currency}.', 'Dringend, {country} {year}: {days} Tage bis zur Verwirkungsfrist vom {deadline}. Danach verfällt der Rückerstattungsanspruch: {amount} {currency}.', 'Urgent, {country} {year} : {days} jours avant le délai péremptoire du {deadline}. Passé cette date le droit au remboursement est perdu : {amount} {currency}.'],
            'foreignVat.alert.EXPIRED' => ['{country} {year}: il termine perentorio del {deadline} è scaduto. IVA non richiesta: {amount} {currency}.', '{country} {year}: the peremptory deadline of {deadline} has expired. Unclaimed VAT: {amount} {currency}.', '{country} {year}: die Verwirkungsfrist vom {deadline} ist abgelaufen. Nicht beantragte MWST: {amount} {currency}.', '{country} {year} : le délai péremptoire du {deadline} est expiré. TVA non demandée : {amount} {currency}.'],
            'foreignVat.kpi.accumulating' => ['IVA rimborsabile in accumulo', 'Recoverable VAT accumulating', 'Rückforderbare MWST in Ansammlung', 'TVA récupérable en cumul'],
            'foreignVat.kpi.ready_to_claim' => ['Pronta da richiedere', 'Ready to claim', 'Bereit zur Beantragung', 'Prête à être demandée'],
            'foreignVat.kpi.submitted_awaiting_refund' => ['Inviata, in attesa di rimborso', 'Submitted, awaiting refund', 'Eingereicht, Rückerstattung ausstehend', 'Envoyée, remboursement en attente'],
            'foreignVat.kpi.refunded_ytd' => ['Rimborsata da inizio anno', 'Refunded year to date', 'Seit Jahresbeginn rückerstattet', 'Remboursée depuis le début de l’année'],
            // Trail of a claim and the files it exports
            'foreignVat.event.created' => ['Istanza creata', 'Claim created', 'Antrag erstellt', 'Demande créée'],
            'foreignVat.event.status_changed' => ['Cambio di stato', 'Status change', 'Statuswechsel', 'Changement de statut'],
            'foreignVat.event.export_generated' => ['File generato', 'File generated', 'Datei erzeugt', 'Fichier généré'],
            'foreignVat.event.refund_reconciled' => ['Rimborso riconciliato', 'Refund reconciled', 'Rückerstattung abgestimmt', 'Remboursement rapproché'],
            'foreignVat.event.deleted' => ['Istanza eliminata', 'Claim deleted', 'Antrag gelöscht', 'Demande supprimée'],
            'foreignVat.exportType.eu-xml' => ['XML portale UE', 'EU portal XML', 'EU-Portal-XML', 'XML portail UE'],
            'foreignVat.exportType.estv-package' => ['Moduli ESTV 1222/1223 + allegati (ZIP)', 'ESTV forms 1222/1223 + attachments (ZIP)', 'ESTV-Formulare 1222/1223 + Beilagen (ZIP)', 'Formulaires AFC 1222/1223 + annexes (ZIP)'],
            'foreignVat.exportType.iva79-package' => ['Modello IVA 79 + allegati (ZIP)', 'Modello IVA 79 + attachments (ZIP)', 'Modello IVA 79 + Beilagen (ZIP)', 'Modello IVA 79 + annexes (ZIP)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'foreignVat.error.domestic_vat' => ['L’IVA del proprio Paese non si rimborsa: segue la liquidazione IVA ordinaria.', 'VAT of your own country is not refunded: it goes through the ordinary VAT settlement.', 'Die MWST des eigenen Landes wird nicht rückerstattet: Sie läuft über die ordentliche Abrechnung.', 'La TVA de votre propre pays n’est pas remboursée : elle suit le décompte TVA ordinaire.'],
            'foreignVat.error.no_procedure' => ['Non esiste una procedura di rimborso per questo Paese.', 'There is no refund procedure for this country.', 'Für dieses Land gibt es kein Rückerstattungsverfahren.', 'Il n’existe pas de procédure de remboursement pour ce pays.'],
            'foreignVat.error.no_reciprocity' => ['Non c’è reciprocità con {country}: l’IVA non è rimborsabile.', 'There is no reciprocity with {country}: the VAT cannot be refunded.', 'Mit {country} besteht keine Gegenseitigkeit: Die MWST ist nicht rückerstattbar.', 'Il n’y a pas de réciprocité avec {country} : la TVA n’est pas remboursable.'],
            'foreignVat.error.period_not_allowed' => ['Questa procedura ammette solo istanze annuali.', 'This procedure accepts annual claims only.', 'Dieses Verfahren lässt nur Jahresanträge zu.', 'Cette procédure n’admet que des demandes annuelles.'],
            'foreignVat.error.no_items' => ['Nessuna IVA estera ammissibile per questo Paese e periodo.', 'No eligible foreign VAT is available for this country and period.', 'Für dieses Land und diesen Zeitraum ist keine berechtigte ausländische MWST vorhanden.', 'Aucune TVA étrangère admissible pour ce pays et cette période.'],
            'foreignVat.error.below_threshold' => ['L’IVA recuperabile è inferiore all’importo minimo di {threshold}.', 'The recoverable VAT is below the minimum amount of {threshold}.', 'Die rückforderbare MWST liegt unter dem Mindestbetrag von {threshold}.', 'La TVA récupérable est inférieure au montant minimal de {threshold}.'],
            'foreignVat.error.deadline_expired' => ['Il termine perentorio del {deadline} è scaduto.', 'The peremptory deadline of {deadline} has expired.', 'Die Verwirkungsfrist vom {deadline} ist abgelaufen.', 'Le délai péremptoire du {deadline} est expiré.'],
            'foreignVat.error.ledger_entry_locked' => ['Questa riga fa già parte di un’istanza e non è più modificabile.', 'This line is already part of a claim and can no longer be changed.', 'Diese Zeile gehört bereits zu einem Antrag und kann nicht mehr geändert werden.', 'Cette ligne fait déjà partie d’une demande et n’est plus modifiable.'],
            'foreignVat.error.claim_locked' => ['L’istanza è già stata inviata: non è più modificabile né eliminabile.', 'The claim was already submitted and can no longer be changed or deleted.', 'Der Antrag wurde bereits eingereicht und kann nicht mehr geändert oder gelöscht werden.', 'La demande a déjà été envoyée et ne peut plus être modifiée ni supprimée.'],
            'foreignVat.error.invalid_transition' => ['L’istanza non può passare da {from} a {to}.', 'The claim cannot move from {from} to {to}.', 'Der Antrag kann nicht von {from} nach {to} wechseln.', 'La demande ne peut pas passer de {from} à {to}.'],
            'foreignVat.error.fiscal_representative_missing' => ['Serve un mandato valido del rappresentante fiscale per {country}.', 'A valid fiscal representative mandate for {country} is required.', 'Ein gültiges Mandat eines Steuervertreters für {country} ist erforderlich.', 'Un mandat valide du représentant fiscal pour {country} est requis.'],
            'foreignVat.error.certificate_missing' => ['Manca l’attestazione di soggettività passiva.', 'The certificate of taxable status is missing.', 'Die Bescheinigung der Steuerpflicht fehlt.', 'L’attestation d’assujettissement est manquante.'],
            'foreignVat.error.certificate_expired' => ['L’attestazione di soggettività passiva è scaduta il {expiry}.', 'The certificate of taxable status expired on {expiry}.', 'Die Bescheinigung der Steuerpflicht ist am {expiry} abgelaufen.', 'L’attestation d’assujettissement a expiré le {expiry}.'],
            'foreignVat.error.attachments_missing' => ['{count} fatture sono senza scansione del documento originale.', '{count} invoices have no scan of the original document.', 'Bei {count} Rechnungen fehlt der Scan des Originalbelegs.', '{count} factures n’ont pas de scan du document original.'],
            'foreignVat.error.approved_amount_invalid' => ['L’importo accolto deve essere tra 0 e l’importo richiesto di {requested}.', 'The approved amount must be between 0 and the requested amount of {requested}.', 'Der bewilligte Betrag muss zwischen 0 und dem beantragten Betrag von {requested} liegen.', 'Le montant accepté doit être compris entre 0 et le montant demandé de {requested}.'],
            'foreignVat.error.refund_amount_required' => ['Indica l’importo accreditato sul conto bancario.', 'Enter the amount received on the bank account.', 'Geben Sie den auf dem Bankkonto eingegangenen Betrag an.', 'Indiquez le montant crédité sur le compte bancaire.'],
            'foreignVat.error.refund_exceeds_approved' => ['L’importo incassato supera l’importo accolto di {approved}.', 'The amount received exceeds the approved amount of {approved}.', 'Der eingegangene Betrag übersteigt den bewilligten Betrag von {approved}.', 'Le montant encaissé dépasse le montant accepté de {approved}.'],
            'foreignVat.error.exchange_rate_required' => ['Indica il cambio {currency}/{base} dell’incasso.', 'State the exchange rate {currency} to {base} of the receipt.', 'Geben Sie den Wechselkurs {currency}/{base} des Zahlungseingangs an.', 'Indiquez le cours {currency}/{base} de l’encaissement.'],
            'foreignVat.error.bank_account_not_found' => ['Il conto bancario non è stato trovato.', 'The bank account was not found.', 'Das Bankkonto wurde nicht gefunden.', 'Le compte bancaire est introuvable.'],
            'foreignVat.error.bank_account_without_ledger_account' => ['Il conto bancario non è collegato a un conto contabile.', 'The bank account is not linked to a ledger account.', 'Das Bankkonto ist keinem Sachkonto zugeordnet.', 'Le compte bancaire n’est pas lié à un compte comptable.'],
            'foreignVat.error.account_role_missing' => ['Il conto contabile per il ruolo {role} non è configurato.', 'The ledger account for the role {role} is not configured.', 'Das Sachkonto für die Rolle {role} ist nicht konfiguriert.', 'Le compte comptable du rôle {role} n’est pas configuré.'],
            'foreignVat.error.not_a_foreign_vat_item' => ['La voce non è soggetta a rimborso dell’IVA estera.', 'The item is not subject to a foreign VAT refund.', 'Die Position unterliegt keiner Rückerstattung ausländischer MWST.', 'La ligne n’est pas soumise au remboursement de la TVA étrangère.'],
            'foreignVat.error.applicant_vat_number_missing' => ['Manca il numero di partita IVA o UID dell’azienda.', 'The VAT or UID number of the company is missing.', 'Die MWST- oder UID-Nummer des Unternehmens fehlt.', 'Le numéro de TVA ou IDE de l’entreprise est manquant.'],
            'foreignVat.error.xml_invalid' => ['L’XML generato non è conforme allo schema.', 'The generated XML does not comply with the schema.', 'Das erzeugte XML entspricht nicht dem Schema.', 'Le XML généré n’est pas conforme au schéma.'],
            'foreignVat.error.vat_rate_not_allowed' => ['L’aliquota IVA {rate}% del documento {document} non è ammessa per questa procedura.', 'The VAT rate {rate}% of document {document} is not allowed for this procedure.', 'Der MWST-Satz {rate}% von Beleg {document} ist für dieses Verfahren nicht zulässig.', 'Le taux de TVA {rate}% du document {document} n’est pas admis pour cette procédure.'],
            'foreignVat.error.export_not_supported_for_regime' => ['Questa esportazione non è disponibile per la procedura {regime}.', 'This export is not available for the procedure {regime}.', 'Dieser Export ist für das Verfahren {regime} nicht verfügbar.', 'Cet export n’est pas disponible pour la procédure {regime}.'],
            'foreignVat.error.export_unknown' => ['Esportazione {type} sconosciuta.', 'Unknown export {type}.', 'Unbekannter Export {type}.', 'Export {type} inconnu.'],
            'foreignVat.error.rule_not_editable' => ['Si possono modificare solo le regole dell’azienda.', 'Only the rules of the company can be changed.', 'Nur die Regeln des Unternehmens können geändert werden.', 'Seules les règles de l’entreprise peuvent être modifiées.'],
            'foreignVat.error.file_not_found' => ['File non trovato.', 'The file was not found.', 'Datei nicht gefunden.', 'Fichier introuvable.'],
            'foreignVat.error.upload_failed' => ['Impossibile salvare il file.', 'The file could not be stored.', 'Die Datei konnte nicht gespeichert werden.', 'Le fichier n’a pas pu être enregistré.'],
            'foreignVat.error.bundle_failed' => ['Impossibile creare il pacchetto ZIP.', 'The ZIP bundle could not be created.', 'Das ZIP-Paket konnte nicht erstellt werden.', 'Le paquet ZIP n’a pas pu être créé.'],
            'foreignVat.error.quarter_required' => ['Scegli il trimestre dell’istanza.', 'Choose the quarter of the claim.', 'Wählen Sie das Quartal des Antrags.', 'Choisissez le trimestre de la demande.'],
            'foreignVat.error.unknown' => ['Operazione non riuscita. Riprova.', 'The operation failed. Try again.', 'Der Vorgang ist fehlgeschlagen. Versuchen Sie es erneut.', 'L’opération a échoué. Réessayez.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'foreignVat.field.documentNumber' => ['Numero documento', 'Document number', 'Belegnummer', 'Numéro du document'],
            'foreignVat.field.documentDate' => ['Data documento', 'Document date', 'Belegdatum', 'Date du document'],
            'foreignVat.field.supplierName' => ['Fornitore', 'Supplier', 'Lieferant', 'Fournisseur'],
            'foreignVat.field.supplierVatNumber' => ['Partita IVA del fornitore', 'Supplier VAT number', 'MWST-Nummer des Lieferanten', 'Numéro de TVA du fournisseur'],
            'foreignVat.field.supplierCountry' => ['Paese del fornitore', 'Supplier country', 'Land des Lieferanten', 'Pays du fournisseur'],
            'foreignVat.field.category' => ['Categoria di spesa (codice 1-10)', 'Expense category (code 1-10)', 'Ausgabenkategorie (Code 1-10)', 'Catégorie de dépense (code 1-10)'],
            'foreignVat.field.netAmount' => ['Imponibile', 'Taxable base', 'Steuerbemessungsgrundlage', 'Base imposable'],
            'foreignVat.field.vatRate' => ['Aliquota IVA estera (%)', 'Foreign VAT rate (%)', 'Ausländischer MWST-Satz (%)', 'Taux de TVA étrangère (%)'],
            'foreignVat.field.vatAmount' => ['Importo IVA estera', 'Foreign VAT amount', 'Betrag der ausländischen MWST', 'Montant de TVA étrangère'],
            'foreignVat.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'foreignVat.field.exchangeRate' => ['Cambio verso la valuta contabile', 'Exchange rate to the accounting currency', 'Wechselkurs zur Buchungswährung', 'Cours vers la monnaie comptable'],
            'foreignVat.field.vatBookedOn' => ['Conto in cui l’IVA è già registrata', 'Account where the VAT is already booked', 'Konto, auf dem die MWST bereits gebucht ist', 'Compte où la TVA est déjà comptabilisée'],
            'foreignVat.field.targetCountry' => ['Paese di rimborso', 'Refund country', 'Rückerstattungsland', 'Pays de remboursement'],
            'foreignVat.field.fiscalYear' => ['Anno fiscale', 'Fiscal year', 'Geschäftsjahr', 'Exercice'],
            'foreignVat.field.periodType' => ['Tipo di periodo', 'Period type', 'Periodentyp', 'Type de période'],
            'foreignVat.field.quarter' => ['Trimestre', 'Quarter', 'Quartal', 'Trimestre'],
            'foreignVat.field.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'foreignVat.field.deadline' => ['Termine perentorio', 'Peremptory deadline', 'Verwirkungsfrist', 'Délai péremptoire'],
            'foreignVat.field.requested' => ['Rimborso richiesto', 'Refund requested', 'Beantragte Rückerstattung', 'Remboursement demandé'],
            'foreignVat.field.approved' => ['Importo accolto', 'Amount approved', 'Bewilligter Betrag', 'Montant accepté'],
            'foreignVat.field.rejected' => ['Importo respinto', 'Amount rejected', 'Abgelehnter Betrag', 'Montant refusé'],
            'foreignVat.field.refunded' => ['Importo rimborsato', 'Amount refunded', 'Rückerstatteter Betrag', 'Montant remboursé'],
            'foreignVat.field.protocol' => ['Numero di protocollo', 'Protocol number', 'Protokollnummer', 'Numéro de protocole'],
            'foreignVat.field.submittedOn' => ['Data di invio', 'Filing date', 'Einreichungsdatum', 'Date de dépôt'],
            'foreignVat.field.note' => ['Nota', 'Note', 'Notiz', 'Note'],
            'foreignVat.field.approvedAmount' => ['Importo accolto dall’amministrazione', 'Amount approved by the authority', 'Von der Behörde bewilligter Betrag', 'Montant accepté par l’administration'],
            'foreignVat.field.refundedAmount' => ['Importo incassato', 'Amount received', 'Eingegangener Betrag', 'Montant encaissé'],
            'foreignVat.field.bankAccount' => ['Conto bancario di accredito', 'Bank account credited', 'Gutgeschriebenes Bankkonto', 'Compte bancaire crédité'],
            'foreignVat.field.receivedOn' => ['Data di incasso', 'Date received', 'Eingangsdatum', 'Date d’encaissement'],
            'foreignVat.field.rejectedAuto' => ['Quota non rimborsata, passata a costo indetraibile', 'Part not refunded, moved to non-deductible cost', 'Nicht rückerstatteter Teil, als nicht abzugsfähiger Aufwand', 'Part non remboursée, passée en charge non déductible'],
            'foreignVat.field.country' => ['Paese', 'Country', 'Land', 'Pays'],
            'foreignVat.field.representativeName' => ['Rappresentante fiscale', 'Fiscal representative', 'Steuervertreter', 'Représentant fiscal'],
            'foreignVat.field.representativeTaxId' => ['Identificativo fiscale (UID / P.IVA)', 'Tax identifier (UID / VAT)', 'Steuernummer (UID / MWST)', 'Identifiant fiscal (IDE / TVA)'],
            'foreignVat.field.address' => ['Indirizzo', 'Address', 'Adresse', 'Adresse'],
            'foreignVat.field.postalCode' => ['CAP', 'Postal code', 'Postleitzahl', 'Code postal'],
            'foreignVat.field.city' => ['Città', 'City', 'Ort', 'Ville'],
            'foreignVat.field.email' => ['Email', 'Email', 'E-Mail', 'E-mail'],
            'foreignVat.field.phone' => ['Telefono', 'Phone', 'Telefon', 'Téléphone'],
            'foreignVat.field.mandateStart' => ['Inizio del mandato', 'Mandate start', 'Mandatsbeginn', 'Début du mandat'],
            'foreignVat.field.mandateEnd' => ['Fine del mandato', 'Mandate end', 'Mandatsende', 'Fin du mandat'],
            'foreignVat.field.certificateExpiry' => ['Scadenza dell’attestazione di soggettività passiva', 'Expiry of the certificate of taxable status', 'Ablauf der Bescheinigung der Steuerpflicht', 'Expiration de l’attestation d’assujettissement'],
            'foreignVat.field.minAnnual' => ['Importo minimo annuale', 'Annual minimum amount', 'Jährlicher Mindestbetrag', 'Montant minimal annuel'],
            'foreignVat.field.minQuarterly' => ['Importo minimo trimestrale', 'Quarterly minimum amount', 'Vierteljährlicher Mindestbetrag', 'Montant minimal trimestriel'],
            'foreignVat.filter.country' => ['Paese', 'Country', 'Land', 'Pays'],
            'foreignVat.filter.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'foreignVat.filter.category' => ['Categoria di spesa', 'Expense category', 'Ausgabenkategorie', 'Catégorie de dépense'],
            'foreignVat.filter.year' => ['Anno', 'Year', 'Jahr', 'Année'],
            'foreignVat.filter.quarter' => ['Trimestre', 'Quarter', 'Quartal', 'Trimestre'],
            'foreignVat.filter.source' => ['Documento di origine', 'Source document', 'Quellbeleg', 'Document d’origine'],
            'foreignVat.filter.search' => ['Cerca documento o fornitore', 'Search document or supplier', 'Beleg oder Lieferant suchen', 'Rechercher document ou fournisseur'],
            'foreignVat.column.document' => ['Documento', 'Document', 'Beleg', 'Document'],
            'foreignVat.column.date' => ['Data', 'Date', 'Datum', 'Date'],
            'foreignVat.column.supplier' => ['Fornitore', 'Supplier', 'Lieferant', 'Fournisseur'],
            'foreignVat.column.country' => ['Paese', 'Country', 'Land', 'Pays'],
            'foreignVat.column.category' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'foreignVat.column.vat' => ['IVA estera', 'Foreign VAT', 'Ausländische MWST', 'TVA étrangère'],
            'foreignVat.column.eligible' => ['Ammissibile', 'Eligible', 'Berechtigt', 'Admissible'],
            'foreignVat.column.recoverable' => ['Rimborsabile', 'Recoverable', 'Rückforderbar', 'Récupérable'],
            'foreignVat.column.nonRecoverable' => ['Non rimborsabile', 'Non-recoverable', 'Nicht rückforderbar', 'Non récupérable'],
            'foreignVat.column.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'foreignVat.column.claim' => ['Istanza', 'Claim', 'Antrag', 'Demande'],
            'foreignVat.column.regime' => ['Procedura', 'Procedure', 'Verfahren', 'Procédure'],
            'foreignVat.column.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'foreignVat.column.deadline' => ['Scadenza', 'Deadline', 'Frist', 'Échéance'],
            'foreignVat.column.requested' => ['Richiesto', 'Requested', 'Beantragt', 'Demandé'],
            'foreignVat.column.refunded' => ['Rimborsato', 'Refunded', 'Rückerstattet', 'Remboursé'],
            'foreignVat.column.scan' => ['Scansione', 'Scan', 'Scan', 'Scan'],
            'foreignVat.column.reciprocity' => ['Reciprocità', 'Reciprocity', 'Gegenseitigkeit', 'Réciprocité'],
            'foreignVat.column.deductible' => ['Detraibilità', 'Deductibility', 'Abzugsfähigkeit', 'Déductibilité'],
            'foreignVat.column.thresholds' => ['Soglie annuale / trimestrale', 'Annual / quarterly thresholds', 'Schwellen jährlich / vierteljährlich', 'Seuils annuel / trimestriel'],
            'foreignVat.column.source' => ['Origine', 'Source', 'Herkunft', 'Origine'],
            'foreignVat.column.representative' => ['Rappresentante', 'Representative', 'Vertreter', 'Représentant'],
            'foreignVat.column.mandate' => ['Mandato', 'Mandate', 'Mandat', 'Mandat'],
            'foreignVat.column.certificate' => ['Attestazione di soggettività passiva', 'Certificate of taxable status', 'Bescheinigung der Steuerpflicht', 'Attestation d’assujettissement'],
            'foreignVat.scan.yes' => ['Allegata', 'Attached', 'Angehängt', 'Jointe'],
            'foreignVat.scan.no' => ['Mancante', 'Missing', 'Fehlt', 'Manquante'],
            'foreignVat.ledger.postingError' => ['Contabilizzazione da verificare', 'Posting needs attention', 'Buchung prüfen', 'Comptabilisation à vérifier'],
            'foreignVat.rules.standard' => ['Standard', 'Standard', 'Standard', 'Standard'],
            'foreignVat.rules.company' => ['Personalizzata', 'Company rule', 'Unternehmensregel', 'Règle de l’entreprise'],
            'foreignVat.rules.defaultsNote' => ['Le percentuali standard sono valori di partenza da verificare ogni anno per ciascun Paese; personalizzale se necessario.', 'The standard percentages are starting values to review every year for each country; customise them when needed.', 'Die Standardsätze sind Ausgangswerte, die jedes Jahr je Land zu prüfen sind; passen Sie sie bei Bedarf an.', 'Les pourcentages standard sont des valeurs de départ à vérifier chaque année pour chaque pays ; personnalisez-les si nécessaire.'],
            'foreignVat.rule.note.estv_reciprocity' => ['Art. 107 cpv. 1 lett. b LIVA e Art. 151–156 OIVA: moduli ESTV 1222 e 1223, scadenza perentoria 30 giugno, minimo 500 CHF, rappresentante fiscale svizzero e attestazione di soggettività passiva.', 'Art. 107 para. 1 lit. b VAT Act and Art. 151–156 VAT Ordinance: ESTV forms 1222 and 1223, peremptory deadline 30 June, CHF 500 minimum, Swiss fiscal representative and certificate of taxable status.', 'Art. 107 Abs. 1 Bst. b MWSTG und Art. 151–156 MWSTV: ESTV-Formulare 1222 und 1223, Verwirkungsfrist 30. Juni, Minimum 500 CHF, Schweizer Steuervertreter und Bescheinigung der Steuerpflicht.', 'Art. 107 al. 1 let. b LTVA et art. 151–156 OTVA : formulaires AFC 1222 et 1223, délai péremptoire 30 juin, minimum 500 CHF, représentant fiscal suisse et attestation d’assujettissement.'],
            'foreignVat.rule.note.it_art_38_ter' => ['Art. 38-ter DPR 633/72: Modello IVA 79 al Centro Operativo di Pescara, scadenza 30 settembre, minimi 400 € trimestrali e 50 € annuali, reciprocità e attestazione di soggettività passiva.', 'Art. 38-ter DPR 633/72: Modello IVA 79 to the Centro Operativo di Pescara, deadline 30 September, minimums EUR 400 quarterly and EUR 50 annual, reciprocity and certificate of taxable status.', 'Art. 38-ter DPR 633/72: Modello IVA 79 an das Centro Operativo di Pescara, Frist 30. September, Minima 400 € vierteljährlich und 50 € jährlich, Gegenseitigkeit und Bescheinigung der Steuerpflicht.', 'Art. 38-ter DPR 633/72 : Modello IVA 79 au Centro Operativo di Pescara, échéance 30 septembre, minimums 400 € par trimestre et 50 € par an, réciprocité et attestation d’assujettissement.'],
            'foreignVat.rule.note.xiii_directive' => ['XIII Direttiva 86/560/CEE per le imprese di Paesi terzi: soglie 400 € trimestrali e 50 € annuali, attestazione di soggettività passiva.', 'Thirteenth Directive 86/560/EEC for businesses of third countries: thresholds EUR 400 quarterly and EUR 50 annual, certificate of taxable status.', 'Dreizehnte Richtlinie 86/560/EWG für Unternehmen aus Drittländern: Schwellen 400 € vierteljährlich und 50 € jährlich, Bescheinigung der Steuerpflicht.', 'Treizième directive 86/560/CEE pour les entreprises de pays tiers : seuils 400 € par trimestre et 50 € par an, attestation d’assujettissement.'],
            'foreignVat.rule.note.eu_directive' => ['Direttiva 2008/9/CE (Art. 38-bis1 DPR 633/72): istanza elettronica tramite portale, scadenza 30 settembre, minimi 400 € trimestrali e 50 € annuali.', 'Directive 2008/9/EC (Art. 38-bis1 DPR 633/72): electronic application through the portal, deadline 30 September, minimums EUR 400 quarterly and EUR 50 annual.', 'Richtlinie 2008/9/EG (Art. 38-bis1 DPR 633/72): elektronischer Antrag über das Portal, Frist 30. September, Minima 400 € vierteljährlich und 50 € jährlich.', 'Directive 2008/9/CE (art. 38-bis1 DPR 633/72) : demande électronique via le portail, échéance 30 septembre, minimums 400 € par trimestre et 50 € par an.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'foreignVat.action.addManual' => ['Registra voce manuale', 'Register manual item', 'Position manuell erfassen', 'Saisir une ligne manuelle'],
            'foreignVat.action.recalculate' => ['Ricalcola', 'Recalculate', 'Neu berechnen', 'Recalculer'],
            'foreignVat.action.attach' => ['Allega scansione', 'Attach scan', 'Scan anhängen', 'Joindre le scan'],
            'foreignVat.action.preview' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'foreignVat.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'foreignVat.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'foreignVat.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'foreignVat.action.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'foreignVat.action.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'foreignVat.action.review' => ['Verifica', 'Review', 'Prüfen', 'Vérifier'],
            'foreignVat.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'foreignVat.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'foreignVat.action.newClaim' => ['Nuova istanza', 'New claim', 'Neuer Antrag', 'Nouvelle demande'],
            'foreignVat.action.createClaim' => ['Crea istanza', 'Create claim', 'Antrag erstellen', 'Créer la demande'],
            'foreignVat.action.openClaims' => ['Istanze di rimborso', 'Refund claims', 'Rückerstattungsanträge', 'Demandes de remboursement'],
            'foreignVat.action.openLedger' => ['Registro IVA estera', 'Foreign VAT ledger', 'Register ausländische MWST', 'Registre de TVA étrangère'],
            'foreignVat.action.markReady' => ['Segna come pronta', 'Mark as ready', 'Als bereit markieren', 'Marquer comme prête'],
            'foreignVat.action.backToDraft' => ['Torna in bozza', 'Back to draft', 'Zurück zum Entwurf', 'Retour au brouillon'],
            'foreignVat.action.markSubmitted' => ['Segna come inviata', 'Mark as submitted', 'Als eingereicht markieren', 'Marquer comme envoyée'],
            'foreignVat.action.logInformationRequest' => ['Registra richiesta di integrazione', 'Log integration request', 'Nachforderung erfassen', 'Enregistrer une demande de pièces'],
            'foreignVat.action.recordDecision' => ['Registra decisione', 'Record decision', 'Entscheid erfassen', 'Enregistrer la décision'],
            'foreignVat.action.reconcile' => ['Riconcilia rimborso', 'Reconcile refund', 'Rückerstattung abstimmen', 'Rapprocher le remboursement'],
            'foreignVat.action.customize' => ['Personalizza', 'Customise', 'Anpassen', 'Personnaliser'],
            'foreignVat.action.revert' => ['Ripristina standard', 'Revert to standard', 'Auf Standard zurücksetzen', 'Rétablir le standard'],
            'foreignVat.action.addMandate' => ['Nuovo mandato', 'New mandate', 'Neues Mandat', 'Nouveau mandat'],
            'foreignVat.action.uploadPowerOfAttorney' => ['Carica procura', 'Upload power of attorney', 'Vollmacht hochladen', 'Téléverser la procuration'],
            'foreignVat.action.uploadCertificate' => ['Carica attestazione', 'Upload certificate', 'Bescheinigung hochladen', 'Téléverser l’attestation'],
            'foreignVat.action.viewPowerOfAttorney' => ['Vedi procura', 'View power of attorney', 'Vollmacht ansehen', 'Voir la procuration'],
            'foreignVat.action.viewCertificate' => ['Vedi attestazione', 'View certificate', 'Bescheinigung ansehen', 'Voir l’attestation'],
            'foreignVat.confirm.deleteEntry' => ['Eliminare questa voce di IVA estera?', 'Delete this foreign VAT item?', 'Diese Position der ausländischen MWST löschen?', 'Supprimer cette ligne de TVA étrangère ?'],
            'foreignVat.confirm.deleteClaim' => ['Eliminare l’istanza? Le sue righe tornano disponibili.', 'Delete the claim? Its lines become available again.', 'Den Antrag löschen? Seine Zeilen werden wieder verfügbar.', 'Supprimer la demande ? Ses lignes redeviennent disponibles.'],
            'foreignVat.confirm.deleteMandate' => ['Eliminare questo mandato?', 'Delete this mandate?', 'Dieses Mandat löschen?', 'Supprimer ce mandat ?'],
            'foreignVat.confirm.revertRule' => ['Rimuovere la regola aziendale e tornare alla regola standard?', 'Remove the company rule and go back to the standard rule?', 'Die Unternehmensregel entfernen und zur Standardregel zurückkehren?', 'Supprimer la règle de l’entreprise et revenir à la règle standard ?'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'foreignVat.section.monitor' => ['Soglie minime e scadenze per Paese', 'Minimum thresholds and deadlines by country', 'Mindestschwellen und Fristen je Land', 'Seuils minimaux et échéances par pays'],
            'foreignVat.section.monitorSubtitle' => ['IVA estera recuperabile accumulata rispetto alle soglie, con il conto alla rovescia verso il 30 giugno (ESTV) e il 30 settembre (UE / Pescara)', 'Recoverable foreign VAT accumulated against the thresholds, with the countdown to 30 June (ESTV) and 30 September (EU / Pescara)', 'Angesammelte rückforderbare ausländische MWST gegenüber den Schwellen, mit Countdown zum 30. Juni (ESTV) und 30. September (EU / Pescara)', 'TVA étrangère récupérable cumulée par rapport aux seuils, avec le compte à rebours vers le 30 juin (AFC) et le 30 septembre (UE / Pescara)'],
            'foreignVat.section.ledgerSubtitle' => ['IVA estera di fatture passive e note spese, divisa tra quota rimborsabile e quota a costo', 'Foreign VAT of supplier invoices and expense reports, split between the recoverable part and the part expensed', 'Ausländische MWST aus Eingangsrechnungen und Spesenabrechnungen, aufgeteilt in rückforderbaren Teil und Aufwandteil', 'TVA étrangère des factures fournisseurs et notes de frais, répartie entre la part récupérable et la part en charge'],
            'foreignVat.section.claimsSubtitle' => ['Dossier di rimborso per Paese e periodo: XML portale UE, moduli ESTV 1222/1223, Modello IVA 79', 'Refund dossiers by country and period: EU portal XML, ESTV forms 1222/1223, Modello IVA 79', 'Rückerstattungsdossiers je Land und Zeitraum: EU-Portal-XML, ESTV-Formulare 1222/1223, Modello IVA 79', 'Dossiers de remboursement par pays et période : XML portail UE, formulaires AFC 1222/1223, Modello IVA 79'],
            'foreignVat.section.configSubtitle' => ['Matrice di reciprocità e detraibilità per Paese, rappresentanti fiscali e attestazioni di soggettività passiva', 'Reciprocity and deductibility matrix by country, fiscal representatives and certificates of taxable status', 'Matrix der Gegenseitigkeit und Abzugsfähigkeit je Land, Steuervertreter und Bescheinigungen der Steuerpflicht', 'Matrice de réciprocité et de déductibilité par pays, représentants fiscaux et attestations d’assujettissement'],
            'foreignVat.section.claimItems' => ['Righe dell’istanza', 'Claim lines', 'Antragszeilen', 'Lignes de la demande'],
            'foreignVat.section.claimEvents' => ['Storico dell’istanza', 'Claim history', 'Antragsverlauf', 'Historique de la demande'],
            'foreignVat.tab.rules' => ['Regole Paesi', 'Country rules', 'Länderregeln', 'Règles par pays'],
            'foreignVat.tab.mandates' => ['Rappresentanti fiscali', 'Fiscal representatives', 'Steuervertreter', 'Représentants fiscaux'],
            'foreignVat.tab.settings' => ['Impostazioni', 'Settings', 'Einstellungen', 'Paramètres'],
            'foreignVat.settings.autoIntercept' => ['Intercetta automaticamente l’IVA estera', 'Intercept foreign VAT automatically', 'Ausländische MWST automatisch erfassen', 'Intercepter automatiquement la TVA étrangère'],
            'foreignVat.settings.autoInterceptHint' => ['Le fatture passive e le note spese con IVA di un altro Paese alimentano il registro e il conto transitorio.', 'Supplier invoices and expense reports with VAT of another country feed the ledger and the transitory account.', 'Eingangsrechnungen und Spesenabrechnungen mit MWST eines anderen Landes speisen das Register und das Durchlaufkonto.', 'Les factures fournisseurs et notes de frais avec TVA d’un autre pays alimentent le registre et le compte transitoire.'],
            'foreignVat.settings.accountsHint' => ['Il conto transitorio dell’IVA estera a rimborso e il conto del costo indetraibile vengono creati nel piano dei conti al primo utilizzo.', 'The transitory account of the foreign VAT refundable and the account of the non-deductible cost are created in the chart of accounts on first use.', 'Das Durchlaufkonto der rückforderbaren ausländischen MWST und das Konto der nicht abzugsfähigen Kosten werden bei der ersten Verwendung im Kontenplan angelegt.', 'Le compte transitoire de la TVA étrangère à rembourser et le compte de la charge non déductible sont créés dans le plan comptable à la première utilisation.'],
            'foreignVat.empty.monitor' => ['Nessuna IVA estera recuperabile in accumulo', 'No recoverable foreign VAT accumulating', 'Keine rückforderbare ausländische MWST in Ansammlung', 'Aucune TVA étrangère récupérable en cumul'],
            'foreignVat.empty.ledger' => ['Nessuna voce di IVA estera', 'No foreign VAT items', 'Keine Positionen ausländischer MWST', 'Aucune ligne de TVA étrangère'],
            'foreignVat.empty.claims' => ['Nessuna istanza di rimborso', 'No refund claims', 'Keine Rückerstattungsanträge', 'Aucune demande de remboursement'],
            'foreignVat.empty.rules' => ['Nessuna regola per questo Paese', 'No rules for this country', 'Keine Regeln für dieses Land', 'Aucune règle pour ce pays'],
            'foreignVat.empty.mandates' => ['Nessun mandato registrato', 'No mandates on file', 'Keine Mandate erfasst', 'Aucun mandat enregistré'],
            'foreignVat.monitor.threshold' => ['Soglia minima annuale', 'Annual minimum threshold', 'Jährliche Mindestschwelle', 'Seuil minimal annuel'],
            'foreignVat.monitor.quarter' => ['T{quarter}', 'Q{quarter}', 'Q{quarter}', 'T{quarter}'],
            'foreignVat.monitor.deadline' => ['Scadenza perentoria', 'Peremptory deadline', 'Verwirkungsfrist', 'Délai péremptoire'],
            'foreignVat.monitor.daysLeft' => ['{days} giorni', '{days} days', '{days} Tage', '{days} jours'],
            'foreignVat.monitor.expired' => ['Scaduto', 'Expired', 'Abgelaufen', 'Expiré'],
            'foreignVat.monitor.representative' => ['Rappresentante e attestazione', 'Representative and certificate', 'Vertreter und Bescheinigung', 'Représentant et attestation'],
            'foreignVat.monitor.otherCurrency' => ['{count} voci in altra valuta non incluse', '{count} items in another currency not included', '{count} Positionen in anderer Währung nicht enthalten', '{count} lignes dans une autre devise non incluses'],
            'foreignVat.modal.manualTitle' => ['Registra IVA estera manuale', 'Register foreign VAT manually', 'Ausländische MWST manuell erfassen', 'Saisir la TVA étrangère manuellement'],
            'foreignVat.modal.wizardTitle' => ['Nuova istanza di rimborso', 'New refund claim', 'Neuer Rückerstattungsantrag', 'Nouvelle demande de remboursement'],
            'foreignVat.wizard.step.period' => ['Paese e periodo', 'Country and period', 'Land und Zeitraum', 'Pays et période'],
            'foreignVat.wizard.step.review' => ['Verifica e conferma', 'Review and confirm', 'Prüfen und bestätigen', 'Vérifier et confirmer'],
            'foreignVat.modal.submitTitle' => ['Registra l’invio dell’istanza', 'Record the filing of the claim', 'Einreichung des Antrags erfassen', 'Enregistrer le dépôt de la demande'],
            'foreignVat.modal.submitBody' => ['Da questo momento le righe dell’istanza sono bloccate.', 'From now on the lines of the claim are locked.', 'Ab jetzt sind die Zeilen des Antrags gesperrt.', 'À partir de maintenant les lignes de la demande sont verrouillées.'],
            'foreignVat.modal.informationTitle' => ['Richiesta di integrazione documentale', 'Request for additional information', 'Nachforderung von Unterlagen', 'Demande de pièces complémentaires'],
            'foreignVat.modal.decideTitle' => ['Decisione dell’amministrazione', 'Decision of the authority', 'Entscheid der Behörde', 'Décision de l’administration'],
            'foreignVat.modal.decideBody' => ['Importo richiesto: {amount} {currency}. La parte respinta passa a costo indetraibile.', 'Amount requested: {amount} {currency}. The rejected part moves to non-deductible cost.', 'Beantragter Betrag: {amount} {currency}. Der abgelehnte Teil wird zu nicht abzugsfähigem Aufwand.', 'Montant demandé : {amount} {currency}. La part refusée passe en charge non déductible.'],
            'foreignVat.modal.reconcileTitle' => ['Riconcilia il rimborso incassato', 'Reconcile the refund received', 'Erhaltene Rückerstattung abstimmen', 'Rapprocher le remboursement encaissé'],
            'foreignVat.modal.reconcileBody' => ['Il bonifico viene registrato in banca e il credito IVA estera viene chiuso; l’eventuale quota non rimborsata passa a costo.', 'The transfer is booked on the bank and the foreign VAT receivable is cleared; any part not refunded moves to cost.', 'Die Überweisung wird auf der Bank gebucht und die Forderung aus ausländischer MWST ausgeglichen; ein nicht rückerstatteter Teil wird zu Aufwand.', 'Le virement est comptabilisé en banque et la créance de TVA étrangère est soldée ; toute part non remboursée passe en charge.'],
            'foreignVat.modal.ruleTitle' => ['Personalizza la regola', 'Customise the rule', 'Regel anpassen', 'Personnaliser la règle'],
            'foreignVat.modal.mandateTitle' => ['Mandato del rappresentante fiscale', 'Fiscal representative mandate', 'Mandat des Steuervertreters', 'Mandat du représentant fiscal'],
            'foreignVat.badge.foreignVat' => ['IVA estera: {country}', 'Foreign VAT: {country}', 'Ausländische MWST: {country}', 'TVA étrangère : {country}'],
            'foreignVat.badge.split' => ['Rimborsabile al {percentage}%: {recoverable} (conto transitorio); a costo: {nonRecoverable}', 'Refundable at {percentage}%: {recoverable} (transitory account); expensed: {nonRecoverable}', 'Rückforderbar zu {percentage}%: {recoverable} (Durchlaufkonto); Aufwand: {nonRecoverable}', 'Remboursable à {percentage}% : {recoverable} (compte transitoire) ; en charge : {nonRecoverable}'],
            'foreignVat.badge.noRefund' => ['Nessun rimborso previsto: l’IVA resta a costo', 'No refund available: the VAT stays a cost', 'Keine Rückerstattung möglich: Die MWST bleibt Aufwand', 'Aucun remboursement possible : la TVA reste une charge'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'foreignVat.toast.entryCreated' => ['Voce di IVA estera registrata', 'Foreign VAT item registered', 'Position der ausländischen MWST erfasst', 'Ligne de TVA étrangère enregistrée'],
            'foreignVat.toast.entryUpdated' => ['Voce ricalcolata', 'Item recalculated', 'Position neu berechnet', 'Ligne recalculée'],
            'foreignVat.toast.entryDeleted' => ['Voce eliminata', 'Item deleted', 'Position gelöscht', 'Ligne supprimée'],
            'foreignVat.toast.attachmentSaved' => ['Scansione allegata', 'Scan attached', 'Scan angehängt', 'Scan joint'],
            'foreignVat.toast.claimCreated' => ['Istanza creata', 'Claim created', 'Antrag erstellt', 'Demande créée'],
            'foreignVat.toast.claimDeleted' => ['Istanza eliminata', 'Claim deleted', 'Antrag gelöscht', 'Demande supprimée'],
            'foreignVat.toast.claimReady' => ['Istanza pronta per l’invio', 'Claim ready to submit', 'Antrag versandbereit', 'Demande prête à l’envoi'],
            'foreignVat.toast.claimDrafted' => ['Istanza tornata in bozza', 'Claim back to draft', 'Antrag wieder im Entwurf', 'Demande revenue en brouillon'],
            'foreignVat.toast.claimSubmitted' => ['Invio registrato, righe bloccate', 'Filing recorded, lines locked', 'Einreichung erfasst, Zeilen gesperrt', 'Dépôt enregistré, lignes verrouillées'],
            'foreignVat.toast.claimInformationRequested' => ['Richiesta di integrazione registrata', 'Integration request logged', 'Nachforderung erfasst', 'Demande de pièces enregistrée'],
            'foreignVat.toast.claimDecided' => ['Decisione registrata', 'Decision recorded', 'Entscheid erfasst', 'Décision enregistrée'],
            'foreignVat.toast.claimReconciled' => ['Rimborso riconciliato', 'Refund reconciled', 'Rückerstattung abgestimmt', 'Remboursement rapproché'],
            'foreignVat.toast.ruleSaved' => ['Regola salvata', 'Rule saved', 'Regel gespeichert', 'Règle enregistrée'],
            'foreignVat.toast.ruleReverted' => ['Regola standard ripristinata', 'Standard rule restored', 'Standardregel wiederhergestellt', 'Règle standard rétablie'],
            'foreignVat.toast.mandateSaved' => ['Mandato salvato', 'Mandate saved', 'Mandat gespeichert', 'Mandat enregistré'],
            'foreignVat.toast.mandateDeleted' => ['Mandato eliminato', 'Mandate deleted', 'Mandat gelöscht', 'Mandat supprimé'],
            'foreignVat.toast.documentSaved' => ['Documento salvato', 'Document saved', 'Dokument gespeichert', 'Document enregistré'],
            'foreignVat.toast.settingsSaved' => ['Impostazioni salvate', 'Settings saved', 'Einstellungen gespeichert', 'Paramètres enregistrés'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function notifications(): array
    {
        return [
            'foreignVat.notification.deadline' => ['Rimborso IVA estera: {days} giorni al termine perentorio del {deadline} per {country} ({year}). IVA non ancora richiesta: {amount} {currency}', 'Foreign VAT refund: {days} days to the peremptory deadline of {deadline} for {country} ({year}). VAT not yet claimed: {amount} {currency}', 'Rückerstattung ausländischer MWST: {days} Tage bis zur Verwirkungsfrist vom {deadline} für {country} ({year}). Noch nicht beantragte MWST: {amount} {currency}', 'Remboursement de TVA étrangère : {days} jours avant le délai péremptoire du {deadline} pour {country} ({year}). TVA pas encore demandée : {amount} {currency}'],
            'foreignVat.notification.deadline_expired' => ['Rimborso IVA estera: termine perentorio del {deadline} scaduto per {country} ({year}). IVA non richiesta: {amount} {currency}', 'Foreign VAT refund: peremptory deadline of {deadline} missed for {country} ({year}). Unclaimed VAT: {amount} {currency}', 'Rückerstattung ausländischer MWST: Verwirkungsfrist vom {deadline} für {country} ({year}) verpasst. Nicht beantragte MWST: {amount} {currency}', 'Remboursement de TVA étrangère : délai péremptoire du {deadline} dépassé pour {country} ({year}). TVA non demandée : {amount} {currency}'],
            'foreignVat.notification.mandate_missing' => ['Rimborso IVA estera: manca un rappresentante fiscale valido per {country}', 'Foreign VAT refund: no valid fiscal representative for {country}', 'Rückerstattung ausländischer MWST: kein gültiger Steuervertreter für {country}', 'Remboursement de TVA étrangère : aucun représentant fiscal valide pour {country}'],
            'foreignVat.notification.certificate_missing' => ['Rimborso IVA estera: manca l’attestazione di soggettività passiva per {country}', 'Foreign VAT refund: certificate of taxable status missing for {country}', 'Rückerstattung ausländischer MWST: Bescheinigung der Steuerpflicht für {country} fehlt', 'Remboursement de TVA étrangère : attestation d’assujettissement manquante pour {country}'],
            'foreignVat.notification.certificate_expiring' => ['Rimborso IVA estera: l’attestazione di soggettività passiva per {country} scade il {expiry}', 'Foreign VAT refund: the certificate of taxable status for {country} expires on {expiry}', 'Rückerstattung ausländischer MWST: Die Bescheinigung der Steuerpflicht für {country} läuft am {expiry} ab', 'Remboursement de TVA étrangère : l’attestation d’assujettissement pour {country} expire le {expiry}'],
            'foreignVat.notification.certificate_expired' => ['Rimborso IVA estera: l’attestazione di soggettività passiva per {country} è scaduta il {expiry}', 'Foreign VAT refund: the certificate of taxable status for {country} expired on {expiry}', 'Rückerstattung ausländischer MWST: Die Bescheinigung der Steuerpflicht für {country} ist am {expiry} abgelaufen', 'Remboursement de TVA étrangère : l’attestation d’assujettissement pour {country} a expiré le {expiry}'],
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
