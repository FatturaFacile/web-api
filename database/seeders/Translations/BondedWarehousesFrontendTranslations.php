<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the bonded warehouse module (Mod 353, Issue #599): customs warehouses (CDU 7100, Swiss OZL / Zollfreilager), IVA warehouses
 * (Art. 50-bis DL 331/93) with the TD22 / TD23 self-invoices, the CGU guarantee monitor and the AEO console. Menus, page titles, tabs, table
 * headers, form labels, statuses, enumerations, tooltips, notices and the codes the API reports (bondedWarehouses.error.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH. Placeholders are {name}.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI) and the names of the procedures (CDU, MRN, TARIC, OZL, CGU, AEO, IM4, EX3, T1,
 * TD22, TD23, F24) stay untranslated.
 */
final class BondedWarehousesFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::errors(),
            self::fields(),
            self::columns(),
            self::actions(),
            self::sections(),
            self::messages(),
            self::findings(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.BONDED_WAREHOUSES' => ['Depositi doganali, depositi IVA e AEO', 'Customs warehouses, IVA warehouses and AEO', 'Zolllager, IVA-Lager und AEO', 'Entrepôts douaniers, entrepôts IVA et AEO'],
            'NAV.BONDED_WAREHOUSES_DASHBOARD' => ['Cruscotto garanzia CGU', 'CGU guarantee dashboard', 'Dashboard CGU-Garantie', 'Tableau de bord garantie CGU'],
            'NAV.BONDED_WAREHOUSES_MRN_LEDGER' => ['Contabilità partite MRN', 'MRN lot ledger', 'MRN-Partienbuchhaltung', 'Comptabilité des lots MRN'],
            'NAV.BONDED_WAREHOUSES_VAT_REGISTER' => ['Depositi IVA e autofatture TD22/TD23', 'IVA warehouses and TD22/TD23 self-invoices', 'IVA-Lager und Eigenrechnungen TD22/TD23', 'Entrepôts IVA et autofactures TD22/TD23'],
            'NAV.BONDED_WAREHOUSES_AEO' => ['Cruscotto AEO', 'AEO console', 'AEO-Konsole', 'Console AEO'],
            'NAV.BONDED_WAREHOUSES_AUTHORIZATIONS' => ['Autorizzazioni dei depositi', 'Warehouse authorizations', 'Lagerbewilligungen', 'Autorisations d’entrepôt'],
            'PAGE_TITLE.BONDED_WAREHOUSES_DASHBOARD' => ['Depositi doganali: massimale e utilizzo della garanzia CGU', 'Customs warehouses: CGU guarantee ceiling and utilization', 'Zolllager: Höchstbetrag und Beanspruchung der CGU-Garantie', 'Entrepôts douaniers : plafond et utilisation de la garantie CGU'],
            'PAGE_TITLE.BONDED_WAREHOUSES_MRN_LEDGER' => ['Contabilità partite MRN — CDU 7100 / OZL', 'MRN lot ledger — CDU 7100 / OZL', 'MRN-Partienbuchhaltung — CDU 7100 / OZL', 'Comptabilité des lots MRN — CDU 7100 / OZL'],
            'PAGE_TITLE.BONDED_WAREHOUSES_VAT_REGISTER' => ['Depositi fiscali IVA (Art. 50-bis) e autofatture TD22/TD23', 'IVA warehouses (Art. 50-bis) and TD22/TD23 self-invoices', 'IVA-Lager (Art. 50-bis) und Eigenrechnungen TD22/TD23', 'Entrepôts IVA (art. 50-bis) et autofactures TD22/TD23'],
            'PAGE_TITLE.BONDED_WAREHOUSES_AEO' => ['Cruscotto AEO — AEOC/AEOS', 'AEO console — AEOC/AEOS', 'AEO-Konsole — AEOC/AEOS', 'Console AEO — AEOC/AEOS'],
            'PAGE_TITLE.BONDED_WAREHOUSES_AUTHORIZATIONS' => ['Autorizzazioni ADM / UDSC dei depositi', 'ADM / UDSC warehouse authorizations', 'Lagerbewilligungen ADM / BAZG', 'Autorisations d’entrepôt ADM / OFDF'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'bondedWarehouses.regime.CDU_Private_7100' => ['Deposito doganale privato (CDU 7100)', 'Private customs warehouse (CDU 7100)', 'Privates Zolllager (CDU 7100)', 'Entrepôt douanier privé (CDU 7100)'],
            'bondedWarehouses.regime.Swiss_OZL' => ['Deposito doganale aperto svizzero (OZL)', 'Swiss open customs warehouse (OZL)', 'Offenes Zolllager (OZL)', 'Entrepôt douanier ouvert suisse (OZL)'],
            'bondedWarehouses.regime.Swiss_Zollfreilager_ZE' => ['Punto franco svizzero (ZE)', 'Swiss free zone (ZE)', 'Zollfreilager (ZE)', 'Entrepôt franc suisse (ZE)'],
            'bondedWarehouses.regime.VatDepot_Art50Bis' => ['Deposito IVA (Art. 50-bis)', 'IVA warehouse (Art. 50-bis)', 'IVA-Lager (Art. 50-bis)', 'Entrepôt IVA (art. 50-bis)'],
            'bondedWarehouses.subtype.Type_C' => ['Tipo C', 'Type C', 'Typ C', 'Type C'],
            'bondedWarehouses.subtype.Type_D' => ['Tipo D', 'Type D', 'Typ D', 'Type D'],
            'bondedWarehouses.subtype.Type_E' => ['Tipo E', 'Type E', 'Typ E', 'Type E'],
            'bondedWarehouses.subtype.Private' => ['Privato', 'Private', 'Privat', 'Privé'],
            'bondedWarehouses.aeoStatus.None' => ['Nessuno', 'None', 'Keiner', 'Aucun'],
            'bondedWarehouses.aeoStatus.AEOC' => ['AEOC', 'AEOC', 'AEOC', 'AEOC'],
            'bondedWarehouses.aeoStatus.AEOS' => ['AEOS', 'AEOS', 'AEOS', 'AEOS'],
            'bondedWarehouses.aeoStatus.AEOF' => ['AEOF', 'AEOF', 'AEOF', 'AEOF'],
            'bondedWarehouses.declaration.IM7' => ['IM7 — vincolo a deposito doganale', 'IM7 — entry into customs warehousing', 'IM7 — Überführung in das Zolllagerverfahren', 'IM7 — placement en entrepôt douanier'],
            'bondedWarehouses.declaration.Passar_Import' => ['Passar — importazione', 'Passar — import', 'Passar — Einfuhr', 'Passar — importation'],
            'bondedWarehouses.declaration.T1' => ['T1 — transito esterno', 'T1 — external transit', 'T1 — externes Versandverfahren', 'T1 — transit externe'],
            'bondedWarehouses.customsStatus.NonUnion_Suspended' => ['Non unionale, sospeso', 'Non-Union, suspended', 'Nichtunionsware, ausgesetzt', 'Non-Union, suspendu'],
            'bondedWarehouses.customsStatus.UsualHandling_InProgress' => ['Manipolazione usuale in corso', 'Usual handling in progress', 'Übliche Behandlung läuft', 'Manipulation usuelle en cours'],
            'bondedWarehouses.customsStatus.PartiallyDischarged' => ['Scaricato in parte', 'Partially discharged', 'Teilweise erledigt', 'Partiellement apuré'],
            'bondedWarehouses.customsStatus.FullyDischarged' => ['Scaricato', 'Fully discharged', 'Vollständig erledigt', 'Entièrement apuré'],
            'bondedWarehouses.dischargeType.IM4_FreeCirculation' => ['IM4 — immissione in libera pratica', 'IM4 — release for free circulation', 'IM4 — Überführung in den zollrechtlich freien Verkehr', 'IM4 — mise en libre pratique'],
            'bondedWarehouses.dischargeType.EX3_ReExport' => ['EX3 — riesportazione', 'EX3 — re-export', 'EX3 — Wiederausfuhr', 'EX3 — réexportation'],
            'bondedWarehouses.dischargeType.Passar_Export' => ['Passar — esportazione', 'Passar — export', 'Passar — Ausfuhr', 'Passar — exportation'],
            'bondedWarehouses.dischargeType.T1_Transit' => ['T1 — transito', 'T1 — transit', 'T1 — Versand', 'T1 — transit'],
            'bondedWarehouses.dischargeType.UsualHandling_Annex71_03' => ['Manipolazione usuale (Allegato 71-03 CDU)', 'Usual handling (Annex 71-03 CDU)', 'Übliche Behandlung (Anhang 71-03 CDU)', 'Manipulation usuelle (annexe 71-03 CDU)'],
            'bondedWarehouses.allocation.FIFO' => ['FIFO — MRN più vecchi per primi', 'FIFO — oldest MRN first', 'FIFO — älteste MRN zuerst', 'FIFO — MRN les plus anciens d’abord'],
            'bondedWarehouses.allocation.Specific_MRN' => ['MRN specifico', 'Specific MRN', 'Bestimmte MRN', 'MRN spécifique'],
            'bondedWarehouses.usualHandling.Repacking' => ['Riconfezionamento', 'Repacking', 'Umverpackung', 'Reconditionnement'],
            'bondedWarehouses.usualHandling.Labeling' => ['Etichettatura', 'Labeling', 'Etikettierung', 'Étiquetage'],
            'bondedWarehouses.usualHandling.Kitting' => ['Composizione di kit', 'Kitting', 'Zusammenstellung von Sets', 'Composition de kits'],
            'bondedWarehouses.usualHandling.Sorting' => ['Cernita', 'Sorting', 'Sortierung', 'Tri'],
            'bondedWarehouses.usualHandling.Cleaning' => ['Pulizia', 'Cleaning', 'Reinigung', 'Nettoyage'],
            'bondedWarehouses.usualHandling.Testing' => ['Controllo e prova', 'Testing', 'Prüfung', 'Contrôle et essai'],
            'bondedWarehouses.usualHandling.Marking' => ['Marcatura', 'Marking', 'Kennzeichnung', 'Marquage'],
            'bondedWarehouses.cguDirection.Engage' => ['Impegno', 'Engagement', 'Belastung', 'Engagement'],
            'bondedWarehouses.cguDirection.Release' => ['Svincolo', 'Release', 'Freigabe', 'Libération'],
            'bondedWarehouses.cguReference.MRN_Inbound' => ['Ingresso MRN', 'MRN entry', 'MRN-Eingang', 'Entrée MRN'],
            'bondedWarehouses.cguReference.MRN_Discharge' => ['Scarico MRN', 'MRN discharge', 'MRN-Erledigung', 'Apurement MRN'],
            'bondedWarehouses.cguReference.Manual_Adjustment' => ['Rettifica manuale', 'Manual adjustment', 'Manuelle Anpassung', 'Ajustement manuel'],
            'bondedWarehouses.vatMovement.Inbound_FreeCirculation' => ['Carico — libera pratica', 'Load — free circulation', 'Zugang — freier Verkehr', 'Entrée — libre pratique'],
            'bondedWarehouses.vatMovement.Inbound_IntraEU' => ['Carico — acquisto intra-UE', 'Load — intra-EU acquisition', 'Zugang — innergemeinschaftlicher Erwerb', 'Entrée — acquisition intra-UE'],
            'bondedWarehouses.vatMovement.Internal_Transfer_NoVAT' => ['Trasferimento interno senza IVA', 'Internal transfer without VAT', 'Interne Übertragung ohne MWST', 'Transfert interne sans TVA'],
            'bondedWarehouses.vatMovement.Extraction_TD22' => ['Estrazione — autofattura TD22', 'Extraction — TD22 self-invoice', 'Auslagerung — Eigenrechnung TD22', 'Extraction — autofacture TD22'],
            'bondedWarehouses.vatMovement.Extraction_TD23' => ['Estrazione — autofattura TD23 con F24', 'Extraction — TD23 self-invoice with F24', 'Auslagerung — Eigenrechnung TD23 mit F24', 'Extraction — autofacture TD23 avec F24'],
            'bondedWarehouses.vatMovement.Extraction_LetterOfIntent' => ['Estrazione con lettera d’intento', 'Extraction with letter of intent', 'Auslagerung mit Absichtserklärung', 'Extraction avec lettre d’intention'],
            'bondedWarehouses.sdiType.TD22' => ['TD22', 'TD22', 'TD22', 'TD22'],
            'bondedWarehouses.sdiType.TD23' => ['TD23', 'TD23', 'TD23', 'TD23'],
            'bondedWarehouses.sdiType.None' => ['—', '—', '—', '—'],
            'bondedWarehouses.auditType.Physical_Book_Reconciliation' => ['Riconciliazione fisico / contabile', 'Physical vs book reconciliation', 'Abgleich Bestand / Buchhaltung', 'Rapprochement physique / comptable'],
            'bondedWarehouses.auditType.Security_Partner_Check' => ['Controllo sicurezza dei partner', 'Partner security check', 'Sicherheitsprüfung der Partner', 'Contrôle de sécurité des partenaires'],
            'bondedWarehouses.auditType.Usual_Handling_Verification' => ['Verifica manipolazioni usuali', 'Usual handling verification', 'Prüfung üblicher Behandlungen', 'Vérification des manipulations usuelles'],
            'bondedWarehouses.auditType.CGU_Threshold_Check' => ['Controllo soglie CGU', 'CGU threshold check', 'Prüfung der CGU-Schwellen', 'Contrôle des seuils CGU'],
            'bondedWarehouses.discrepancy.Matched' => ['Conforme', 'Matched', 'Übereinstimmend', 'Conforme'],
            'bondedWarehouses.discrepancy.Minor_Variance' => ['Scostamento lieve', 'Minor variance', 'Geringe Abweichung', 'Écart mineur'],
            'bondedWarehouses.discrepancy.Critical_Alert_ADM_UDSC' => ['Allerta critica ADM / UDSC', 'Critical alert ADM / UDSC', 'Kritische Meldung ADM / BAZG', 'Alerte critique ADM / OFDF'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'bondedWarehouses.error.unknown' => ['Operazione non riuscita.', 'The operation failed.', 'Der Vorgang ist fehlgeschlagen.', 'L’opération a échoué.'],
            'bondedWarehouses.error.record_immutable' => ['Questa registrazione fa parte della pista di audit e non è modificabile.', 'This record is part of the audit trail and cannot be changed.', 'Dieser Eintrag gehört zum Prüfpfad und kann nicht geändert werden.', 'Cet enregistrement fait partie de la piste d’audit et ne peut pas être modifié.'],
            'bondedWarehouses.error.authorization_not_found' => ['Autorizzazione non trovata.', 'Authorization not found.', 'Bewilligung nicht gefunden.', 'Autorisation introuvable.'],
            'bondedWarehouses.error.authorization_inactive' => ['L’autorizzazione {code} non è attiva o non è valida alla data dell’operazione.', 'Authorization {code} is not active or not valid on the date of the operation.', 'Die Bewilligung {code} ist nicht aktiv oder zum Zeitpunkt des Vorgangs nicht gültig.', 'L’autorisation {code} n’est pas active ou n’est pas valable à la date de l’opération.'],
            'bondedWarehouses.error.regime_not_customs_warehouse' => ['Questa autorizzazione è un deposito IVA: usa il registro Art. 50-bis.', 'This authorization is an IVA warehouse: use the Art. 50-bis register.', 'Diese Bewilligung betrifft ein IVA-Lager: verwenden Sie das Register nach Art. 50-bis.', 'Cette autorisation est un entrepôt IVA : utilisez le registre de l’art. 50-bis.'],
            'bondedWarehouses.error.regime_not_vat_depot' => ['Questa autorizzazione non è un deposito IVA Art. 50-bis.', 'This authorization is not an Art. 50-bis IVA warehouse.', 'Diese Bewilligung ist kein IVA-Lager nach Art. 50-bis.', 'Cette autorisation n’est pas un entrepôt IVA de l’art. 50-bis.'],
            'bondedWarehouses.error.declaration_type_not_allowed' => ['La dichiarazione {declaration} non è ammessa per il regime {regime}.', 'Declaration {declaration} is not allowed for regime {regime}.', 'Die Anmeldung {declaration} ist für das Regime {regime} nicht zulässig.', 'La déclaration {declaration} n’est pas admise pour le régime {regime}.'],
            'bondedWarehouses.error.invalid_mrn' => ['L’MRN deve avere 18 caratteri: anno, Paese e 14 caratteri alfanumerici.', 'The MRN must have 18 characters: year, country and 14 alphanumerics.', 'Die MRN muss 18 Zeichen haben: Jahr, Land und 14 alphanumerische Zeichen.', 'Le MRN doit comporter 18 caractères : année, pays et 14 caractères alphanumériques.'],
            'bondedWarehouses.error.invalid_discharge_mrn' => ['Indica l’MRN valido della dichiarazione di svincolo.', 'Enter the valid MRN of the discharge declaration.', 'Geben Sie die gültige MRN der Erledigungsanmeldung an.', 'Indiquez le MRN valide de la déclaration d’apurement.'],
            'bondedWarehouses.error.invalid_taric' => ['Il codice TARIC deve avere 8 o 10 cifre.', 'The TARIC code must have 8 or 10 digits.', 'Der TARIC-Code muss 8 oder 10 Ziffern haben.', 'Le code TARIC doit comporter 8 ou 10 chiffres.'],
            'bondedWarehouses.error.mrn_already_registered' => ['L’MRN {mrn} voce {item} è già registrato.', 'MRN {mrn} item {item} is already registered.', 'Die MRN {mrn} Position {item} ist bereits erfasst.', 'Le MRN {mrn} article {item} est déjà enregistré.'],
            'bondedWarehouses.error.cgu_ceiling_exceeded' => ['Il massimale CGU sarebbe superato: disponibile {available} {currency}, richiesto {requested} {currency}.', 'The CGU ceiling would be exceeded: available {available} {currency}, requested {requested} {currency}.', 'Der CGU-Höchstbetrag würde überschritten: verfügbar {available} {currency}, verlangt {requested} {currency}.', 'Le plafond CGU serait dépassé : disponible {available} {currency}, demandé {requested} {currency}.'],
            'bondedWarehouses.error.cgu_release_exceeds_utilized' => ['Lo svincolo supera la garanzia utilizzata.', 'The release exceeds the guarantee in use.', 'Die Freigabe übersteigt die beanspruchte Garantie.', 'La libération dépasse la garantie utilisée.'],
            'bondedWarehouses.error.ceiling_below_utilized' => ['Il massimale non può essere inferiore alla garanzia già utilizzata ({utilized}).', 'The ceiling cannot be lower than the guarantee already in use ({utilized}).', 'Der Höchstbetrag darf nicht unter der bereits beanspruchten Garantie ({utilized}) liegen.', 'Le plafond ne peut pas être inférieur à la garantie déjà utilisée ({utilized}).'],
            'bondedWarehouses.error.authorization_in_use' => ['L’autorizzazione ha partite o garanzia impegnata: non si può eliminare.', 'The authorization has lots or an engaged guarantee: it cannot be deleted.', 'Die Bewilligung hat Partien oder eine belastete Garantie: sie kann nicht gelöscht werden.', 'L’autorisation a des lots ou une garantie engagée : elle ne peut pas être supprimée.'],
            'bondedWarehouses.error.quantity_must_be_positive' => ['La quantità deve essere maggiore di zero.', 'The quantity must be greater than zero.', 'Die Menge muss grösser als null sein.', 'La quantité doit être supérieure à zéro.'],
            'bondedWarehouses.error.insufficient_remaining_quantity' => ['Quantità residua insufficiente: disponibile {available}, richiesta {requested}.', 'Insufficient remaining quantity: available {available}, requested {requested}.', 'Restmenge nicht ausreichend: verfügbar {available}, verlangt {requested}.', 'Quantité restante insuffisante : disponible {available}, demandée {requested}.'],
            'bondedWarehouses.error.insufficient_remaining_weight' => ['Peso netto residuo insufficiente per l’MRN {mrn}.', 'Insufficient remaining net weight for MRN {mrn}.', 'Restnettogewicht der MRN {mrn} nicht ausreichend.', 'Poids net restant insuffisant pour le MRN {mrn}.'],
            'bondedWarehouses.error.lot_not_found' => ['Partita MRN non trovata.', 'MRN lot not found.', 'MRN-Partie nicht gefunden.', 'Lot MRN introuvable.'],
            'bondedWarehouses.error.discharge_before_entry' => ['La data di scarico precede l’ingresso dell’MRN {mrn}.', 'The discharge date precedes the entry of MRN {mrn}.', 'Das Erledigungsdatum liegt vor dem Eingang der MRN {mrn}.', 'La date d’apurement précède l’entrée du MRN {mrn}.'],
            'bondedWarehouses.error.usual_handling_code_required' => ['Indica l’operazione di manipolazione usuale (Allegato 71-03 CDU).', 'Enter the usual handling operation (Annex 71-03 CDU).', 'Geben Sie die übliche Behandlung an (Anhang 71-03 CDU).', 'Indiquez la manipulation usuelle (annexe 71-03 CDU).'],
            'bondedWarehouses.error.fifo_needs_product_and_quantity' => ['Lo scarico FIFO richiede prodotto e quantità.', 'A FIFO discharge needs a product and a quantity.', 'Eine FIFO-Erledigung braucht Produkt und Menge.', 'Un apurement FIFO exige un produit et une quantité.'],
            'bondedWarehouses.error.movement_not_inbound' => ['Il movimento non è un carico del deposito IVA.', 'The movement is not a load of the IVA warehouse.', 'Die Bewegung ist kein Zugang des IVA-Lagers.', 'Le mouvement n’est pas une entrée de l’entrepôt IVA.'],
            'bondedWarehouses.error.movement_not_extraction' => ['Il movimento non è un’estrazione dal deposito IVA.', 'The movement is not an extraction from the IVA warehouse.', 'Die Bewegung ist keine Auslagerung aus dem IVA-Lager.', 'Le mouvement n’est pas une extraction de l’entrepôt IVA.'],
            'bondedWarehouses.error.negative_value' => ['I valori non possono essere negativi.', 'Values cannot be negative.', 'Werte dürfen nicht negativ sein.', 'Les valeurs ne peuvent pas être négatives.'],
            'bondedWarehouses.error.f24_required' => ['L’estrazione TD23 richiede riferimento e data del versamento F24.', 'A TD23 extraction needs the F24 payment reference and date.', 'Eine TD23-Auslagerung braucht Referenz und Datum der F24-Zahlung.', 'Une extraction TD23 exige la référence et la date du paiement F24.'],
            'bondedWarehouses.error.f24_not_allowed' => ['Il versamento F24 è previsto solo per le estrazioni TD23.', 'The F24 payment applies to TD23 extractions only.', 'Die F24-Zahlung gilt nur für TD23-Auslagerungen.', 'Le paiement F24 ne concerne que les extractions TD23.'],
            'bondedWarehouses.error.insufficient_depot_stock' => ['Giacenza del deposito IVA insufficiente: disponibile {available}, richiesta {requested}.', 'Insufficient IVA warehouse stock: available {available}, requested {requested}.', 'Bestand des IVA-Lagers nicht ausreichend: verfügbar {available}, verlangt {requested}.', 'Stock de l’entrepôt IVA insuffisant : disponible {available}, demandé {requested}.'],
            'bondedWarehouses.error.self_contact_missing' => ['Manca l’anagrafica della tua azienda (stessa partita IVA) per emettere l’autofattura.', 'Your own company contact (same VAT number) is missing, so the self-invoice cannot be issued.', 'Der Kontakt Ihres eigenen Unternehmens (gleiche MwSt-Nummer) fehlt: die Eigenrechnung kann nicht ausgestellt werden.', 'Le contact de votre propre entreprise (même numéro de TVA) est absent : l’autofacture ne peut pas être émise.'],
            'bondedWarehouses.error.unknown_dataset' => ['Esportazione non disponibile.', 'Export not available.', 'Export nicht verfügbar.', 'Export non disponible.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'bondedWarehouses.filter.mrn' => ['MRN', 'MRN', 'MRN', 'MRN'],
            'bondedWarehouses.filter.taric' => ['Codice TARIC', 'TARIC code', 'TARIC-Code', 'Code TARIC'],
            'bondedWarehouses.filter.regime' => ['Regime', 'Regime', 'Regime', 'Régime'],
            'bondedWarehouses.filter.origin' => ['Origine', 'Origin', 'Ursprung', 'Origine'],
            'bondedWarehouses.filter.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'bondedWarehouses.filter.hasBalance' => ['Solo con residuo', 'With balance only', 'Nur mit Restbestand', 'Avec solde uniquement'],
            'bondedWarehouses.filter.authorization' => ['Autorizzazione', 'Authorization', 'Bewilligung', 'Autorisation'],
            'bondedWarehouses.filter.movementType' => ['Tipo di movimento', 'Movement type', 'Bewegungsart', 'Type de mouvement'],
            'bondedWarehouses.filter.dateFrom' => ['Dal', 'From', 'Von', 'Du'],
            'bondedWarehouses.filter.dateTo' => ['Al', 'To', 'Bis', 'Au'],
            'bondedWarehouses.filter.auditType' => ['Tipo di controllo', 'Audit type', 'Prüfungsart', 'Type de contrôle'],
            'bondedWarehouses.filter.discrepancy' => ['Esito', 'Result', 'Ergebnis', 'Résultat'],
            'bondedWarehouses.filter.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'bondedWarehouses.field.authorization' => ['Autorizzazione', 'Authorization', 'Bewilligung', 'Autorisation'],
            'bondedWarehouses.field.authorizationCode' => ['Codice autorizzazione', 'Authorization code', 'Bewilligungscode', 'Code d’autorisation'],
            'bondedWarehouses.field.customsOffice' => ['Ufficio doganale', 'Customs office', 'Zollstelle', 'Bureau de douane'],
            'bondedWarehouses.field.warehouse' => ['Magazzino WMS', 'WMS warehouse', 'WMS-Lager', 'Entrepôt WMS'],
            'bondedWarehouses.field.regime' => ['Regime', 'Regime', 'Regime', 'Régime'],
            'bondedWarehouses.field.subtype' => ['Tipo di deposito', 'Warehouse type', 'Lagertyp', 'Type d’entrepôt'],
            'bondedWarehouses.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'bondedWarehouses.field.cguReference' => ['Riferimento garanzia CGU', 'CGU guarantee reference', 'Referenz der CGU-Garantie', 'Référence de la garantie CGU'],
            'bondedWarehouses.field.cguCeiling' => ['Massimale CGU', 'CGU ceiling', 'CGU-Höchstbetrag', 'Plafond CGU'],
            'bondedWarehouses.field.aeoStatus' => ['Stato AEO', 'AEO status', 'AEO-Status', 'Statut AEO'],
            'bondedWarehouses.field.aeoCertificate' => ['Numero certificato AEO', 'AEO certificate number', 'AEO-Zertifikatsnummer', 'Numéro du certificat AEO'],
            'bondedWarehouses.field.aeoNextAudit' => ['Prossimo audit AEO', 'Next AEO audit', 'Nächstes AEO-Audit', 'Prochain audit AEO'],
            'bondedWarehouses.field.validFrom' => ['Valida dal', 'Valid from', 'Gültig ab', 'Valable dès'],
            'bondedWarehouses.field.validTo' => ['Valida fino al', 'Valid until', 'Gültig bis', 'Valable jusqu’au'],
            'bondedWarehouses.field.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'bondedWarehouses.field.mrn' => ['MRN (18 caratteri)', 'MRN (18 characters)', 'MRN (18 Zeichen)', 'MRN (18 caractères)'],
            'bondedWarehouses.field.mrnItem' => ['Voce della dichiarazione', 'Declaration item', 'Position der Anmeldung', 'Article de la déclaration'],
            'bondedWarehouses.field.declarationType' => ['Tipo di dichiarazione', 'Declaration type', 'Anmeldeart', 'Type de déclaration'],
            'bondedWarehouses.field.entryDate' => ['Data di ingresso', 'Entry date', 'Eingangsdatum', 'Date d’entrée'],
            'bondedWarehouses.field.product' => ['Prodotto', 'Product', 'Produkt', 'Produit'],
            'bondedWarehouses.field.taric' => ['Codice TARIC / NC', 'TARIC / CN code', 'TARIC- / KN-Code', 'Code TARIC / NC'],
            'bondedWarehouses.field.origin' => ['Paese di origine', 'Country of origin', 'Ursprungsland', 'Pays d’origine'],
            'bondedWarehouses.field.preferentialOrigin' => ['Origine preferenziale', 'Preferential origin', 'Präferenzursprung', 'Origine préférentielle'],
            'bondedWarehouses.field.agreementCode' => ['Codice dell’accordo', 'Agreement code', 'Code des Abkommens', 'Code de l’accord'],
            'bondedWarehouses.field.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'bondedWarehouses.field.unit' => ['Unità di misura', 'Unit of measure', 'Masseinheit', 'Unité de mesure'],
            'bondedWarehouses.field.netWeight' => ['Peso netto (kg)', 'Net weight (kg)', 'Nettogewicht (kg)', 'Poids net (kg)'],
            'bondedWarehouses.field.grossWeight' => ['Peso lordo (kg)', 'Gross weight (kg)', 'Bruttogewicht (kg)', 'Poids brut (kg)'],
            'bondedWarehouses.field.customsValue' => ['Valore statistico doganale', 'Customs statistical value', 'Statistischer Zollwert', 'Valeur statistique en douane'],
            'bondedWarehouses.field.dutyRate' => ['Aliquota dazio %', 'Duty rate %', 'Zollsatz %', 'Taux de droit %'],
            'bondedWarehouses.field.vatRate' => ['Aliquota IVA %', 'VAT rate %', 'MWST-Satz %', 'Taux de TVA %'],
            'bondedWarehouses.field.dischargeType' => ['Tipo di scarico', 'Discharge type', 'Erledigungsart', 'Type d’apurement'],
            'bondedWarehouses.field.allocationMethod' => ['Metodo di imputazione', 'Allocation method', 'Zuordnungsmethode', 'Méthode d’imputation'],
            'bondedWarehouses.field.dischargeDate' => ['Data di scarico', 'Discharge date', 'Erledigungsdatum', 'Date d’apurement'],
            'bondedWarehouses.field.dischargeMrn' => ['MRN della dichiarazione di svincolo', 'MRN of the discharge declaration', 'MRN der Erledigungsanmeldung', 'MRN de la déclaration d’apurement'],
            'bondedWarehouses.field.usualHandling' => ['Operazione di manipolazione usuale', 'Usual handling operation', 'Übliche Behandlung', 'Manipulation usuelle'],
            'bondedWarehouses.field.mrnLot' => ['Partita MRN', 'MRN lot', 'MRN-Partie', 'Lot MRN'],
            'bondedWarehouses.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'bondedWarehouses.field.movementType' => ['Tipo di movimento', 'Movement type', 'Bewegungsart', 'Type de mouvement'],
            'bondedWarehouses.field.documentType' => ['Autofattura', 'Self-invoice', 'Eigenrechnung', 'Autofacture'],
            'bondedWarehouses.field.movementDate' => ['Data del movimento', 'Movement date', 'Bewegungsdatum', 'Date du mouvement'],
            'bondedWarehouses.field.lotReference' => ['Riferimento lotto', 'Lot reference', 'Partienreferenz', 'Référence du lot'],
            'bondedWarehouses.field.goodsValue' => ['Valore dei beni', 'Value of the goods', 'Warenwert', 'Valeur des biens'],
            'bondedWarehouses.field.servicesValue' => ['Servizi di magazzinaggio e lavorazione', 'Storage and processing services', 'Lagerungs- und Bearbeitungsleistungen', 'Services de stockage et de transformation'],
            'bondedWarehouses.field.f24Reference' => ['Riferimento versamento F24', 'F24 payment reference', 'Referenz der F24-Zahlung', 'Référence du paiement F24'],
            'bondedWarehouses.field.f24Date' => ['Data versamento F24', 'F24 payment date', 'Datum der F24-Zahlung', 'Date du paiement F24'],
            'bondedWarehouses.field.partnerName' => ['Vettore o fornitore', 'Carrier or supplier', 'Frachtführer oder Lieferant', 'Transporteur ou fournisseur'],
            'bondedWarehouses.field.partnerAeoStatus' => ['Stato AEO del partner', 'Partner AEO status', 'AEO-Status des Partners', 'Statut AEO du partenaire'],
            'bondedWarehouses.field.sealVerified' => ['Sigillo verificato', 'Seal verified', 'Plombe geprüft', 'Scellé vérifié'],
            'bondedWarehouses.field.findings' => ['Rilievi', 'Findings', 'Feststellungen', 'Constats'],
            'bondedWarehouses.help.suspended' => ['Dazio e IVA sospesi sono calcolati dal valore e dalle aliquote e impegnano la garanzia CGU.', 'Suspended duty and VAT are calculated from the value and the rates and engage the CGU guarantee.', 'Ausgesetzte Zölle und MWST werden aus Wert und Sätzen berechnet und belasten die CGU-Garantie.', 'Les droits et la TVA suspendus sont calculés à partir de la valeur et des taux et engagent la garantie CGU.'],
            'bondedWarehouses.help.usualHandling' => ['La manipolazione usuale non genera debito doganale e mantiene la tracciabilità dell’MRN di origine.', 'Usual handling creates no customs debt and keeps the lineage of the original MRN.', 'Die übliche Behandlung begründet keine Zollschuld und erhält die Rückverfolgbarkeit der ursprünglichen MRN.', 'La manipulation usuelle ne crée pas de dette douanière et conserve la traçabilité du MRN d’origine.'],
            'bondedWarehouses.help.extraction' => ['Base imponibile = valore dei beni + servizi resi nel deposito (art. 50-bis, c. 4). TD22: IVA nella liquidazione periodica; TD23: IVA versata con F24 non compensabile.', 'Taxable base = value of the goods + services rendered in the warehouse (Art. 50-bis, para. 4). TD22: VAT in the periodic settlement; TD23: VAT paid with a non-compensable F24.', 'Bemessungsgrundlage = Warenwert + im Lager erbrachte Leistungen (Art. 50-bis Abs. 4). TD22: MWST in der periodischen Abrechnung; TD23: MWST mit nicht verrechenbarem F24 bezahlt.', 'Base imposable = valeur des biens + services rendus dans l’entrepôt (art. 50-bis, al. 4). TD22 : TVA dans la liquidation périodique ; TD23 : TVA payée par F24 non compensable.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function columns(): array
    {
        return [
            'bondedWarehouses.column.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'bondedWarehouses.column.aeoCertificate' => ['Certificato AEO', 'AEO certificate', 'AEO-Zertifikat', 'Certificat AEO'],
            'bondedWarehouses.column.aeoStatus' => ['AEO', 'AEO', 'AEO', 'AEO'],
            'bondedWarehouses.column.auditType' => ['Controllo', 'Audit', 'Prüfung', 'Contrôle'],
            'bondedWarehouses.column.authorization' => ['Autorizzazione', 'Authorization', 'Bewilligung', 'Autorisation'],
            'bondedWarehouses.column.available' => ['Disponibile', 'Available', 'Verfügbar', 'Disponible'],
            'bondedWarehouses.column.cguEngaged' => ['CGU impegnata', 'CGU engaged', 'CGU belastet', 'CGU engagée'],
            'bondedWarehouses.column.cguGauge' => ['Utilizzo CGU', 'CGU utilization', 'CGU-Beanspruchung', 'Utilisation CGU'],
            'bondedWarehouses.column.cguReleased' => ['CGU svincolata', 'CGU released', 'CGU freigegeben', 'CGU libérée'],
            'bondedWarehouses.column.customsValue' => ['Valore doganale', 'Customs value', 'Zollwert', 'Valeur en douane'],
            'bondedWarehouses.column.date' => ['Data', 'Date', 'Datum', 'Date'],
            'bondedWarehouses.column.declaration' => ['Dichiarazione', 'Declaration', 'Anmeldung', 'Déclaration'],
            'bondedWarehouses.column.delta' => ['Variazione', 'Change', 'Veränderung', 'Variation'],
            'bondedWarehouses.column.direction' => ['Verso', 'Direction', 'Richtung', 'Sens'],
            'bondedWarehouses.column.dischargeMrn' => ['MRN di svincolo', 'Discharge MRN', 'Erledigungs-MRN', 'MRN d’apurement'],
            'bondedWarehouses.column.dischargeType' => ['Tipo di scarico', 'Discharge type', 'Erledigungsart', 'Type d’apurement'],
            'bondedWarehouses.column.discrepancy' => ['Differenza', 'Difference', 'Differenz', 'Écart'],
            'bondedWarehouses.column.document' => ['Documento SDI', 'SDI document', 'SDI-Dokument', 'Document SDI'],
            'bondedWarehouses.column.duty' => ['Dazio', 'Duty', 'Zoll', 'Droits'],
            'bondedWarehouses.column.entryDate' => ['Ingresso', 'Entry', 'Eingang', 'Entrée'],
            'bondedWarehouses.column.f24' => ['F24', 'F24', 'F24', 'F24'],
            'bondedWarehouses.column.findings' => ['Rilievi', 'Findings', 'Feststellungen', 'Constats'],
            'bondedWarehouses.column.goodsValue' => ['Valore beni', 'Goods value', 'Warenwert', 'Valeur des biens'],
            'bondedWarehouses.column.initialQuantity' => ['Quantità iniziale', 'Initial quantity', 'Anfangsmenge', 'Quantité initiale'],
            'bondedWarehouses.column.ledgerQuantity' => ['Libro doganale', 'Customs book', 'Zollbuch', 'Livre douanier'],
            'bondedWarehouses.column.lot' => ['Lotto', 'Lot', 'Partie', 'Lot'],
            'bondedWarehouses.column.movementType' => ['Movimento', 'Movement', 'Bewegung', 'Mouvement'],
            'bondedWarehouses.column.mrn' => ['MRN / voce', 'MRN / item', 'MRN / Position', 'MRN / article'],
            'bondedWarehouses.column.netWeight' => ['Peso netto', 'Net weight', 'Nettogewicht', 'Poids net'],
            'bondedWarehouses.column.nextAudit' => ['Prossimo audit', 'Next audit', 'Nächstes Audit', 'Prochain audit'],
            'bondedWarehouses.column.office' => ['Ufficio', 'Office', 'Zollstelle', 'Bureau'],
            'bondedWarehouses.column.origin' => ['Origine', 'Origin', 'Ursprung', 'Origine'],
            'bondedWarehouses.column.partner' => ['Partner', 'Partner', 'Partner', 'Partenaire'],
            'bondedWarehouses.column.physical' => ['Fisico WMS', 'WMS physical', 'WMS-Bestand', 'Physique WMS'],
            'bondedWarehouses.column.progressive' => ['N. progressivo', 'Progressive no.', 'Laufnummer', 'N° progressif'],
            'bondedWarehouses.column.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'bondedWarehouses.column.recordedAt' => ['Registrato il', 'Recorded', 'Erfasst am', 'Enregistré le'],
            'bondedWarehouses.column.reference' => ['Riferimento', 'Reference', 'Referenz', 'Référence'],
            'bondedWarehouses.column.regime' => ['Regime', 'Regime', 'Regime', 'Régime'],
            'bondedWarehouses.column.remaining' => ['Residuo', 'Remaining', 'Rest', 'Reste'],
            'bondedWarehouses.column.remainingPlafond' => ['Plafond residuo', 'Remaining plafond', 'Restplafond', 'Plafond restant'],
            'bondedWarehouses.column.result' => ['Esito', 'Result', 'Ergebnis', 'Résultat'],
            'bondedWarehouses.column.runningBalance' => ['Utilizzo progressivo', 'Running balance', 'Laufender Stand', 'Solde cumulé'],
            'bondedWarehouses.column.servicesValue' => ['Servizi', 'Services', 'Leistungen', 'Services'],
            'bondedWarehouses.column.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'bondedWarehouses.column.suspendedDuty' => ['Dazio sospeso', 'Suspended duty', 'Ausgesetzter Zoll', 'Droits suspendus'],
            'bondedWarehouses.column.suspendedVat' => ['IVA sospesa', 'Suspended VAT', 'Ausgesetzte MWST', 'TVA suspendue'],
            'bondedWarehouses.column.taric' => ['TARIC', 'TARIC', 'TARIC', 'TARIC'],
            'bondedWarehouses.column.taxableBase' => ['Base imponibile', 'Taxable base', 'Bemessungsgrundlage', 'Base imposable'],
            'bondedWarehouses.column.validity' => ['Validità', 'Validity', 'Gültigkeit', 'Validité'],
            'bondedWarehouses.column.vat' => ['IVA', 'VAT', 'MWST', 'TVA'],
            'bondedWarehouses.column.warehouse' => ['Magazzino', 'Warehouse', 'Lager', 'Entrepôt'],
            'bondedWarehouses.kpi.aeoScore' => ['Punteggio conformità AEO', 'AEO compliance score', 'AEO-Compliance-Wert', 'Score de conformité AEO'],
            'bondedWarehouses.kpi.audits90' => ['Controlli negli ultimi 90 giorni', 'Audits in the last 90 days', 'Prüfungen der letzten 90 Tage', 'Contrôles des 90 derniers jours'],
            'bondedWarehouses.kpi.cguAvailable' => ['Plafond disponibile', 'Available plafond', 'Verfügbares Plafond', 'Plafond disponible'],
            'bondedWarehouses.kpi.cguCeiling' => ['Massimale CGU totale', 'Total CGU ceiling', 'CGU-Höchstbetrag gesamt', 'Plafond CGU total'],
            'bondedWarehouses.kpi.cguUtilized' => ['Utilizzato (dazi e IVA sospesi)', 'Utilized (suspended duties and VAT)', 'Beansprucht (ausgesetzte Zölle und MWST)', 'Utilisé (droits et TVA suspendus)'],
            'bondedWarehouses.kpi.expiring' => ['Autorizzazioni in scadenza', 'Expiring authorizations', 'Ablaufende Bewilligungen', 'Autorisations arrivant à échéance'],
            'bondedWarehouses.kpi.openLots' => ['Partite MRN con residuo', 'MRN lots with balance', 'MRN-Partien mit Restbestand', 'Lots MRN avec solde'],
            'bondedWarehouses.kpi.usualHandling' => ['Manipolazioni usuali in corso', 'Usual handling in progress', 'Laufende übliche Behandlungen', 'Manipulations usuelles en cours'],
            'bondedWarehouses.tag.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'bondedWarehouses.tag.inactive' => ['Non attiva', 'Inactive', 'Inaktiv', 'Inactive'],
            'bondedWarehouses.tag.preferential' => ['Preferenziale', 'Preferential', 'Präferenz', 'Préférentielle'],
            'bondedWarehouses.tag.auditOverdue' => ['Audit scaduto', 'Audit overdue', 'Audit überfällig', 'Audit en retard'],
            'bondedWarehouses.alert.cguWarning' => ['Garanzia oltre l’80%', 'Guarantee above 80%', 'Garantie über 80 %', 'Garantie au-delà de 80 %'],
            'bondedWarehouses.alert.cguCritical' => ['Garanzia oltre il 95%', 'Guarantee above 95%', 'Garantie über 95 %', 'Garantie au-delà de 95 %'],
            'bondedWarehouses.alert.expiring' => ['{count} autorizzazioni scadono entro 60 giorni.', '{count} authorizations expire within 60 days.', '{count} Bewilligungen laufen innert 60 Tagen ab.', '{count} autorisations expirent dans les 60 jours.'],
            'bondedWarehouses.empty.audits' => ['Nessun controllo registrato.', 'No audit recorded.', 'Keine Prüfung erfasst.', 'Aucun contrôle enregistré.'],
            'bondedWarehouses.empty.authorizations' => ['Nessuna autorizzazione.', 'No authorization.', 'Keine Bewilligung.', 'Aucune autorisation.'],
            'bondedWarehouses.empty.certified' => ['Nessuna autorizzazione con certificato AEO.', 'No authorization with an AEO certificate.', 'Keine Bewilligung mit AEO-Zertifikat.', 'Aucune autorisation avec certificat AEO.'],
            'bondedWarehouses.empty.discharges' => ['Nessuno scarico su questa partita.', 'No discharge on this lot.', 'Keine Erledigung dieser Partie.', 'Aucun apurement sur ce lot.'],
            'bondedWarehouses.empty.ledger' => ['Nessun movimento di garanzia.', 'No guarantee movement.', 'Keine Garantiebewegung.', 'Aucun mouvement de garantie.'],
            'bondedWarehouses.empty.lots' => ['Nessuna partita MRN.', 'No MRN lot.', 'Keine MRN-Partie.', 'Aucun lot MRN.'],
            'bondedWarehouses.empty.register' => ['Nessuna registrazione nel registro.', 'No row in the register.', 'Keine Einträge im Register.', 'Aucune ligne dans le registre.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'bondedWarehouses.action.addAllocation' => ['Aggiungi partita', 'Add lot', 'Partie hinzufügen', 'Ajouter un lot'],
            'bondedWarehouses.action.addPartner' => ['Aggiungi controllo partner', 'Add partner check', 'Partnerprüfung hinzufügen', 'Ajouter un contrôle de partenaire'],
            'bondedWarehouses.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'bondedWarehouses.action.close' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
            'bondedWarehouses.action.confirmDelete' => ['Eliminare l’autorizzazione?', 'Delete the authorization?', 'Bewilligung löschen?', 'Supprimer l’autorisation ?'],
            'bondedWarehouses.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'bondedWarehouses.action.discharge' => ['Scarica dal deposito', 'Discharge from warehouse', 'Aus dem Lager erledigen', 'Apurer de l’entrepôt'],
            'bondedWarehouses.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'bondedWarehouses.action.emitSelfInvoice' => ['Emetti autofattura SDI', 'Emit SDI self-invoice', 'SDI-Eigenrechnung ausstellen', 'Émettre l’autofacture SDI'],
            'bondedWarehouses.action.execute' => ['Esegui scarico', 'Execute discharge', 'Erledigung ausführen', 'Exécuter l’apurement'],
            'bondedWarehouses.action.exportCsv' => ['Esporta CSV', 'Export CSV', 'CSV exportieren', 'Exporter en CSV'],
            'bondedWarehouses.action.exportPdf' => ['Esporta PDF', 'Export PDF', 'PDF exportieren', 'Exporter en PDF'],
            'bondedWarehouses.action.newAuthorization' => ['Nuova autorizzazione', 'New authorization', 'Neue Bewilligung', 'Nouvelle autorisation'],
            'bondedWarehouses.action.newExtraction' => ['Estrazione e autofattura', 'Extraction and self-invoice', 'Auslagerung und Eigenrechnung', 'Extraction et autofacture'],
            'bondedWarehouses.action.newInbound' => ['Ingresso MRN', 'MRN entry', 'MRN-Eingang', 'Entrée MRN'],
            'bondedWarehouses.action.newVatInbound' => ['Carico deposito IVA', 'IVA warehouse load', 'Zugang IVA-Lager', 'Entrée entrepôt IVA'],
            'bondedWarehouses.action.preview' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'bondedWarehouses.action.removeAllocation' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'bondedWarehouses.action.removePartner' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'bondedWarehouses.action.runReconciliation' => ['Esegui riconciliazione', 'Run reconciliation', 'Abgleich ausführen', 'Lancer le rapprochement'],
            'bondedWarehouses.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'bondedWarehouses.action.simulate' => ['Simula', 'Simulate', 'Simulieren', 'Simuler'],
            'bondedWarehouses.action.traceability' => ['Tracciabilità', 'Traceability', 'Rückverfolgbarkeit', 'Traçabilité'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'bondedWarehouses.section.aeoSubtitle' => ['Riconciliazione fisico WMS / libro doganale, soglie CGU e sicurezza della catena logistica (art. 39 CDU).', 'WMS physical vs customs book reconciliation, CGU thresholds and supply chain security (Art. 39 CDU).', 'Abgleich WMS-Bestand / Zollbuch, CGU-Schwellen und Sicherheit der Lieferkette (Art. 39 UZK-DA).', 'Rapprochement physique WMS / livre douanier, seuils CGU et sûreté de la chaîne logistique (art. 39 CDU).'],
            'bondedWarehouses.section.allocations' => ['Partite MRN da scaricare', 'MRN lots to discharge', 'Zu erledigende MRN-Partien', 'Lots MRN à apurer'],
            'bondedWarehouses.section.audits' => ['Registro dei controlli', 'Audit log', 'Prüfprotokoll', 'Registre des contrôles'],
            'bondedWarehouses.section.authorization' => ['Autorizzazione del deposito', 'Warehouse authorization', 'Lagerbewilligung', 'Autorisation d’entrepôt'],
            'bondedWarehouses.section.authorizationsSubtitle' => ['Autorizzazioni ADM / UDSC con massimale CGU e certificato AEO.', 'ADM / UDSC authorizations with CGU ceiling and AEO certificate.', 'Bewilligungen ADM / BAZG mit CGU-Höchstbetrag und AEO-Zertifikat.', 'Autorisations ADM / OFDF avec plafond CGU et certificat AEO.'],
            'bondedWarehouses.section.certified' => ['Autorizzazioni con certificato AEO', 'Authorizations with an AEO certificate', 'Bewilligungen mit AEO-Zertifikat', 'Autorisations avec certificat AEO'],
            'bondedWarehouses.section.cguLedger' => ['Registro della garanzia CGU', 'CGU guarantee ledger', 'CGU-Garantiebuch', 'Registre de la garantie CGU'],
            'bondedWarehouses.section.cguMonitor' => ['Monitor del plafond CGU per autorizzazione', 'CGU plafond monitor by authorization', 'CGU-Plafond-Monitor pro Bewilligung', 'Moniteur du plafond CGU par autorisation'],
            'bondedWarehouses.section.dashboardSubtitle' => ['Massimale, utilizzo (dazi e IVA sospesi) e plafond disponibile della garanzia globale, scadenze e conformità AEO.', 'Ceiling, utilization (suspended duties and VAT) and available plafond of the comprehensive guarantee, expiries and AEO compliance.', 'Höchstbetrag, Beanspruchung (ausgesetzte Zölle und MWST) und verfügbares Plafond der Gesamtgarantie, Fristen und AEO-Compliance.', 'Plafond, utilisation (droits et TVA suspendus) et plafond disponible de la garantie globale, échéances et conformité AEO.'],
            'bondedWarehouses.section.discharge' => ['Scarico dal deposito doganale', 'Discharge from the customs warehouse', 'Erledigung aus dem Zolllager', 'Apurement de l’entrepôt douanier'],
            'bondedWarehouses.section.discharges' => ['Scarichi e svincoli della partita', 'Discharges and clearances of the lot', 'Erledigungen und Abfertigungen der Partie', 'Apurements et dédouanements du lot'],
            'bondedWarehouses.section.executed' => ['Scarico registrato', 'Discharge recorded', 'Erledigung erfasst', 'Apurement enregistré'],
            'bondedWarehouses.section.extraction' => ['Estrazione dal deposito IVA', 'Extraction from the IVA warehouse', 'Auslagerung aus dem IVA-Lager', 'Extraction de l’entrepôt IVA'],
            'bondedWarehouses.section.inbound' => ['Ingresso in deposito sotto MRN', 'Entry into the warehouse under an MRN', 'Eingang ins Lager unter einer MRN', 'Entrée en entrepôt sous MRN'],
            'bondedWarehouses.section.ledgerSubtitle' => ['Giacenza vincolata per MRN e voce, con tracciabilità fino a ogni IM4, EX3, T1 e manipolazione usuale.', 'Bonded stock per MRN and item, traceable to every IM4, EX3, T1 and usual handling.', 'Gebundener Bestand pro MRN und Position, rückverfolgbar bis zu jeder IM4, EX3, T1 und üblichen Behandlung.', 'Stock sous douane par MRN et article, traçable jusqu’à chaque IM4, EX3, T1 et manipulation usuelle.'],
            'bondedWarehouses.section.partnerChecks' => ['Controlli di sicurezza di vettori e fornitori', 'Security checks of carriers and suppliers', 'Sicherheitsprüfungen von Frachtführern und Lieferanten', 'Contrôles de sûreté des transporteurs et fournisseurs'],
            'bondedWarehouses.section.reconciliation' => ['Riconciliazione AEO', 'AEO reconciliation', 'AEO-Abgleich', 'Rapprochement AEO'],
            'bondedWarehouses.section.simulation' => ['Simulazione dello scarico', 'Discharge simulation', 'Simulation der Erledigung', 'Simulation de l’apurement'],
            'bondedWarehouses.section.traceability' => ['Tracciabilità della partita MRN', 'Traceability of the MRN lot', 'Rückverfolgbarkeit der MRN-Partie', 'Traçabilité du lot MRN'],
            'bondedWarehouses.section.vatInbound' => ['Carico nel registro del deposito IVA', 'Load into the IVA warehouse register', 'Zugang im Register des IVA-Lagers', 'Entrée dans le registre de l’entrepôt IVA'],
            'bondedWarehouses.section.vatSubtitle' => ['Registro telematico di carico e scarico (Art. 50-bis DL 331/93) con numerazione progressiva ed estrazioni con autofattura TD22/TD23.', 'Telematic load / unload register (Art. 50-bis DL 331/93) with progressive numbering and extractions with a TD22/TD23 self-invoice.', 'Telematisches Zu- und Abgangsregister (Art. 50-bis DL 331/93) mit Laufnummern und Auslagerungen mit Eigenrechnung TD22/TD23.', 'Registre télématique d’entrées et de sorties (art. 50-bis DL 331/93) avec numérotation progressive et extractions avec autofacture TD22/TD23.'],
            'bondedWarehouses.summary.cguReleased' => ['Garanzia svincolata', 'Guarantee released', 'Freigegebene Garantie', 'Garantie libérée'],
            'bondedWarehouses.summary.customsValue' => ['Valore doganale', 'Customs value', 'Zollwert', 'Valeur en douane'],
            'bondedWarehouses.summary.debtArises' => ['Sorge il debito doganale', 'Customs debt arises', 'Zollschuld entsteht', 'Une dette douanière naît'],
            'bondedWarehouses.summary.documentType' => ['Documento', 'Document', 'Dokument', 'Document'],
            'bondedWarehouses.summary.dutyDue' => ['Dazio dovuto', 'Duty due', 'Geschuldeter Zoll', 'Droits dus'],
            'bondedWarehouses.summary.extinguished' => ['Dazio e IVA estinti', 'Duty and VAT extinguished', 'Erloschene Zölle und MWST', 'Droits et TVA éteints'],
            'bondedWarehouses.summary.noDebt' => ['Nessun debito doganale', 'No customs debt', 'Keine Zollschuld', 'Aucune dette douanière'],
            'bondedWarehouses.summary.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'bondedWarehouses.summary.taxableBase' => ['Base imponibile', 'Taxable base', 'Bemessungsgrundlage', 'Base imposable'],
            'bondedWarehouses.summary.vatAmount' => ['IVA', 'VAT', 'MWST', 'TVA'],
            'bondedWarehouses.summary.vatDue' => ['IVA dovuta', 'VAT due', 'Geschuldete MWST', 'TVA due'],
            'bondedWarehouses.preview.stockOk' => ['Giacenza sufficiente nel deposito IVA: {available} disponibili.', 'Sufficient stock in the IVA warehouse: {available} available.', 'Genügend Bestand im IVA-Lager: {available} verfügbar.', 'Stock suffisant dans l’entrepôt IVA : {available} disponibles.'],
            'bondedWarehouses.preview.stockShort' => ['Giacenza insufficiente nel deposito IVA: {available} disponibili.', 'Insufficient stock in the IVA warehouse: {available} available.', 'Zu wenig Bestand im IVA-Lager: {available} verfügbar.', 'Stock insuffisant dans l’entrepôt IVA : {available} disponibles.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'bondedWarehouses.message.authorizationSaved' => ['L’autorizzazione è stata salvata.', 'The authorization was saved.', 'Die Bewilligung wurde gespeichert.', 'L’autorisation a été enregistrée.'],
            'bondedWarehouses.message.authorizationDeleted' => ['L’autorizzazione è stata eliminata.', 'The authorization was deleted.', 'Die Bewilligung wurde gelöscht.', 'L’autorisation a été supprimée.'],
            'bondedWarehouses.message.inboundRegistered' => ['Ingresso registrato e garanzia CGU impegnata.', 'Entry registered and CGU guarantee engaged.', 'Eingang erfasst und CGU-Garantie belastet.', 'Entrée enregistrée et garantie CGU engagée.'],
            'bondedWarehouses.message.dischargeExecuted' => ['Scarico registrato e quota di garanzia svincolata.', 'Discharge recorded and guarantee share released.', 'Erledigung erfasst und Garantieanteil freigegeben.', 'Apurement enregistré et part de garantie libérée.'],
            'bondedWarehouses.message.vatInboundRegistered' => ['Carico registrato nel registro del deposito IVA.', 'Load recorded in the IVA warehouse register.', 'Zugang im Register des IVA-Lagers erfasst.', 'Entrée enregistrée dans le registre de l’entrepôt IVA.'],
            'bondedWarehouses.message.extractionEmitted' => ['Estrazione registrata e autofattura elettronica creata.', 'Extraction recorded and electronic self-invoice created.', 'Auslagerung erfasst und elektronische Eigenrechnung erstellt.', 'Extraction enregistrée et autofacture électronique créée.'],
            'bondedWarehouses.message.reconciliationDone' => ['Riconciliazione eseguita: {critical} critici, {minor} lievi, {matched} conformi.', 'Reconciliation run: {critical} critical, {minor} minor, {matched} matched.', 'Abgleich ausgeführt: {critical} kritisch, {minor} gering, {matched} übereinstimmend.', 'Rapprochement exécuté : {critical} critiques, {minor} mineurs, {matched} conformes.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function findings(): array
    {
        return [
            'bondedWarehouses.finding.book_matches_physical' => ['Il libro doganale coincide con il fisico.', 'The customs book matches the physical stock.', 'Das Zollbuch stimmt mit dem Bestand überein.', 'Le livre douanier correspond au stock physique.'],
            'bondedWarehouses.finding.physical_shortfall' => ['Ammanco fisico rispetto al libro doganale.', 'Physical shortfall against the customs book.', 'Fehlbestand gegenüber dem Zollbuch.', 'Manquant physique par rapport au livre douanier.'],
            'bondedWarehouses.finding.physical_surplus' => ['Eccedenza fisica non registrata nel libro doganale.', 'Physical surplus not booked in the customs book.', 'Überbestand, der nicht im Zollbuch erfasst ist.', 'Excédent physique non enregistré dans le livre douanier.'],
            'bondedWarehouses.finding.cgu_utilization_ok' => ['Utilizzo della garanzia CGU nella norma.', 'CGU guarantee utilization within limits.', 'Beanspruchung der CGU-Garantie im Rahmen.', 'Utilisation de la garantie CGU dans les limites.'],
            'bondedWarehouses.finding.cgu_utilization_warning' => ['Garanzia CGU utilizzata oltre l’80%.', 'CGU guarantee used above 80%.', 'CGU-Garantie zu über 80 % beansprucht.', 'Garantie CGU utilisée à plus de 80 %.'],
            'bondedWarehouses.finding.cgu_utilization_critical' => ['Garanzia CGU utilizzata oltre il 95%.', 'CGU guarantee used above 95%.', 'CGU-Garantie zu über 95 % beansprucht.', 'Garantie CGU utilisée à plus de 95 %.'],
            'bondedWarehouses.finding.usual_handling_in_progress' => ['Manipolazione usuale in corso.', 'Usual handling in progress.', 'Übliche Behandlung läuft.', 'Manipulation usuelle en cours.'],
            'bondedWarehouses.finding.usual_handling_open_too_long' => ['Manipolazione usuale aperta da troppo tempo.', 'Usual handling open for too long.', 'Übliche Behandlung seit zu langer Zeit offen.', 'Manipulation usuelle ouverte depuis trop longtemps.'],
            'bondedWarehouses.finding.partner_secure' => ['Partner sicuro: AEO e sigillo verificati.', 'Secure partner: AEO and seal verified.', 'Sicherer Partner: AEO und Plombe geprüft.', 'Partenaire sûr : AEO et scellé vérifiés.'],
            'bondedWarehouses.finding.partner_without_aeo' => ['Partner senza certificato AEO.', 'Partner without an AEO certificate.', 'Partner ohne AEO-Zertifikat.', 'Partenaire sans certificat AEO.'],
            'bondedWarehouses.finding.seal_not_verified' => ['Sigillo non verificato.', 'Seal not verified.', 'Plombe nicht geprüft.', 'Scellé non vérifié.'],
            'bondedWarehouses.finding.increase_guarantee_or_discharge_stock' => ['Aumentare la garanzia o scaricare merce.', 'Increase the guarantee or discharge stock.', 'Garantie erhöhen oder Ware erledigen.', 'Augmenter la garantie ou apurer des marchandises.'],
            'bondedWarehouses.finding.close_usual_handling' => ['Chiudere la manipolazione usuale.', 'Close the usual handling.', 'Die übliche Behandlung abschliessen.', 'Clôturer la manipulation usuelle.'],
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
