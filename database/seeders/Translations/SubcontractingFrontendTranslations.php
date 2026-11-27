<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the subcontracting module (Mod 065, conto lavoro passivo and attivo): the stock-at-subcontractors dashboard, the order
 * wizard and workspace with their quick actions (dispatch with the DDT Conto lavoro, receipt with backflush and cost capitalization,
 * return of unused material, customer material at zero value, delivery with the DDT Reso lavorato), the Art. 53 DPR 633/72 register and
 * the TPP / TPA summary, plus the codes the API reports (subcontracting.error.<code>). The enumerations and the error texts are taken
 * from the backend dictionary (lang/{it,en,de,fr}/subcontracting.php) so the two never drift apart.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, DDT, TPP, TPA, MRN, BOM) stay untranslated.
 */
final class SubcontractingFrontendTranslations
{
    private const array Locales = ['it', 'en', 'de', 'fr'];

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
            self::actions(),
            self::sections(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.SUBCONTRACTING' => ['Lavoro conto terzi', 'Subcontracting', 'Lohnfertigung', 'Sous-traitance'],
            'NAV.SUBCONTRACTING_DASHBOARD' => ['Giacenze presso terzisti', 'Stock at subcontractors', 'Bestände bei Lohnfertigern', 'Stocks chez les sous-traitants'],
            'NAV.SUBCONTRACTING_ORDERS' => ['Ordini di conto lavoro', 'Subcontracting orders', 'Lohnfertigungsaufträge', 'Ordres de sous-traitance'],
            'NAV.SUBCONTRACTING_ORDER_NEW' => ['Nuovo ordine', 'New order', 'Neuer Auftrag', 'Nouvel ordre'],
            'NAV.SUBCONTRACTING_ORDER_DETAIL' => ['Dettaglio ordine', 'Order detail', 'Auftragsdetail', 'Détail de l’ordre'],
            'NAV.SUBCONTRACTING_REGISTER' => ['Registro conto lavoro', 'Conto lavoro register', 'Lohnfertigungsregister', 'Registre de sous-traitance'],
            'PAGE_TITLE.SUBCONTRACTING_DASHBOARD' => ['Cruscotto giacenze presso terzisti', 'Stock at subcontractors dashboard', 'Dashboard der Bestände bei Lohnfertigern', 'Tableau de bord des stocks chez les sous-traitants'],
            'PAGE_TITLE.SUBCONTRACTING_ORDERS' => ['Ordini di conto lavoro passivo e attivo', 'Passive and active subcontracting orders', 'Passive und aktive Lohnfertigungsaufträge', 'Ordres de sous-traitance passive et active'],
            'PAGE_TITLE.SUBCONTRACTING_ORDER_NEW' => ['Nuovo ordine di conto lavoro', 'New subcontracting order', 'Neuer Lohnfertigungsauftrag', 'Nouvel ordre de sous-traitance'],
            'PAGE_TITLE.SUBCONTRACTING_ORDER_DETAIL' => ['Ordine di conto lavoro', 'Subcontracting order', 'Lohnfertigungsauftrag', 'Ordre de sous-traitance'],
            'PAGE_TITLE.SUBCONTRACTING_REGISTER' => ['Registro delle merci in conto lavoro (Art. 53 DPR 633/72) e TPP/TPA', 'Register of goods in conto lavoro (Art. 53 DPR 633/72) and TPP/TPA', 'Register der Waren in Lohnfertigung (Art. 53 DPR 633/72) und TPP/TPA', 'Registre des marchandises en sous-traitance (art. 53 DPR 633/72) et TPP/TPA'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        $fromBackend = [];

        foreach (['passive', 'active'] as $value) {
            $fromBackend['subcontracting.direction.'.$value] = self::backend('export.direction_'.$value);
        }
        foreach (['outbound_dispatch', 'inbound_consumption_backflush', 'scrap_declared', 'unused_material_return', 'customer_material_receipt', 'customer_finished_return'] as $value) {
            $fromBackend['subcontracting.movementType.'.$value] = self::backend('export.movement_type_'.$value);
        }
        foreach (['ok', 'warning', 'expired', 'closed'] as $value) {
            $fromBackend['subcontracting.deadlineStatus.'.$value] = self::backend('export.deadline_status_'.$value);
        }

        return $fromBackend + [
            'subcontracting.type.full_bom' => ['Distinta completa', 'Full BOM', 'Komplette Stückliste', 'Nomenclature complète'],
            'subcontracting.type.single_operation' => ['Singola fase di ciclo', 'Single routing operation', 'Einzelner Arbeitsgang', 'Opération de gamme unique'],

            'subcontracting.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'subcontracting.status.confirmed' => ['Confermato', 'Confirmed', 'Bestätigt', 'Confirmé'],
            'subcontracting.status.materials_dispatched' => ['Materiali inviati', 'Materials dispatched', 'Material versandt', 'Matériel expédié'],
            'subcontracting.status.in_progress' => ['In lavorazione', 'In progress', 'In Bearbeitung', 'En cours'],
            'subcontracting.status.partially_returned' => ['Parzialmente rientrato', 'Partially returned', 'Teilweise zurück', 'Partiellement retourné'],
            'subcontracting.status.completed' => ['Completato', 'Completed', 'Abgeschlossen', 'Terminé'],
            'subcontracting.status.cancelled' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],

            'subcontracting.customsRegime.none' => ['Nessun regime doganale', 'No customs regime', 'Kein Zollverfahren', 'Aucun régime douanier'],
            'subcontracting.customsRegime.tpp_outward_processing' => ['TPP – Perfezionamento passivo', 'TPP – Outward processing', 'TPP – Passive Veredelung', 'TPP – Perfectionnement passif'],
            'subcontracting.customsRegime.tpa_inward_processing' => ['TPA – Perfezionamento attivo', 'TPA – Inward processing', 'TPA – Aktive Veredelung', 'TPA – Perfectionnement actif'],

            'subcontracting.causal.subcontracting' => ['Conto lavorazione', 'Subcontracting (conto lavoro)', 'Lohnfertigung (Conto Lavoro)', 'Sous-traitance (conto lavoro)'],
            'subcontracting.causal.subcontractor_return' => ['Reso da terzista', 'Return from subcontractor', 'Rücklieferung vom Lohnfertiger', 'Retour du sous-traitant'],
            'subcontracting.causal.subcontracted_goods_return' => ['Reso lavorato', 'Return of processed goods', 'Rücklieferung bearbeiteter Ware', 'Retour de marchandises usinées'],

            'subcontracting.export.csv' => ['Esporta CSV', 'Export CSV', 'CSV exportieren', 'Exporter en CSV'],
            'subcontracting.export.xlsx' => ['Esporta Excel', 'Export Excel', 'Excel exportieren', 'Exporter en Excel'],
            'subcontracting.export.pdf' => ['Esporta PDF', 'Export PDF', 'PDF exportieren', 'Exporter en PDF'],
        ];
    }

    /**
     * The refusals of the API, in the four languages of the backend dictionary, with `:param` turned into `{param}`.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        $codes = array_keys(self::dictionary('en')['errors']);
        $errors = [
            'subcontracting.error.generic' => ['Operazione non riuscita. Riprova.', 'The operation failed. Try again.', 'Der Vorgang ist fehlgeschlagen. Versuchen Sie es erneut.', 'L’opération a échoué. Réessayez.'],
        ];

        foreach ($codes as $code) {
            $errors['subcontracting.error.'.$code] = array_map(
                static fn (string $text): string => (string) preg_replace('/:([a-z_]+)/', '{$1}', $text),
                self::backend('errors.'.$code),
            );
        }

        return $errors;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'subcontracting.field.actions' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'subcontracting.field.agingDays' => ['Giorni presso il partner', 'Days at partner', 'Tage beim Partner', 'Jours chez le partenaire'],
            'subcontracting.field.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'subcontracting.field.balance' => ['Saldo', 'Balance', 'Saldo', 'Solde'],
            'subcontracting.field.bom' => ['Distinta base (BOM)', 'Bill of materials (BOM)', 'Stückliste (BOM)', 'Nomenclature (BOM)'],
            'subcontracting.field.carrier' => ['Vettore', 'Carrier', 'Frachtführer', 'Transporteur'],
            'subcontracting.field.causal' => ['Causale', 'Causal', 'Transportgrund', 'Motif'],
            'subcontracting.field.component' => ['Componente', 'Component', 'Komponente', 'Composant'],
            'subcontracting.field.consumed' => ['Consumato', 'Consumed', 'Verbraucht', 'Consommé'],
            'subcontracting.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'subcontracting.field.customer' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'subcontracting.field.customerDdtDate' => ['Data DDT del cliente', 'Customer DDT date', 'Lieferscheindatum des Kunden', 'Date du bon de livraison du client'],
            'subcontracting.field.customerDdtNumber' => ['Numero DDT del cliente', 'Customer DDT number', 'Lieferscheinnummer des Kunden', 'Numéro du bon de livraison du client'],
            'subcontracting.field.customerSalesOrder' => ['Ordine di vendita del cliente', 'Customer sales order', 'Kundenauftrag', 'Commande client'],
            'subcontracting.field.customsDeclaration' => ['Dichiarazione doganale', 'Customs declaration', 'Zollanmeldung', 'Déclaration en douane'],
            'subcontracting.field.customsRegime' => ['Regime doganale', 'Customs regime', 'Zollverfahren', 'Régime douanier'],
            'subcontracting.field.date' => ['Data', 'Date', 'Datum', 'Date'],
            'subcontracting.field.ddt' => ['DDT', 'DDT', 'Lieferschein', 'Bon de livraison'],
            'subcontracting.field.deadline' => ['Termine di rientro', 'Return deadline', 'Rückgabefrist', 'Délai de retour'],
            'subcontracting.field.deadlineStatus' => ['Stato del termine', 'Deadline status', 'Fristenstatus', 'État du délai'],
            'subcontracting.field.declaration' => ['Dichiarazione', 'Declaration', 'Anmeldung', 'Déclaration'],
            'subcontracting.field.deliverQuantity' => ['Quantità da consegnare', 'Quantity to deliver', 'Zu liefernde Menge', 'Quantité à livrer'],
            'subcontracting.field.deliveredQuantity' => ['Quantità consegnata', 'Delivered quantity', 'Gelieferte Menge', 'Quantité livrée'],
            'subcontracting.field.direction' => ['Direzione', 'Direction', 'Richtung', 'Sens'],
            'subcontracting.field.dispatched' => ['Inviato', 'Dispatched', 'Versandt', 'Expédié'],
            'subcontracting.field.entryDate' => ['Data movimento', 'Entry date', 'Buchungsdatum', 'Date du mouvement'],
            'subcontracting.field.expectedReturnDate' => ['Rientro previsto', 'Expected return', 'Erwartete Rückgabe', 'Retour prévu'],
            'subcontracting.field.expiry' => ['Scadenza doganale', 'Customs expiry', 'Zollablauf', 'Échéance douanière'],
            'subcontracting.field.generatePurchaseOrder' => ['Genera l’ordine di acquisto del servizio (Mod 046)', 'Generate the service purchase order (Mod 046)', 'Bestellung der Dienstleistung erzeugen (Mod 046)', 'Générer la commande d’achat du service (Mod 046)'],
            'subcontracting.field.goodQuantity' => ['Quantità buona', 'Good quantity', 'Gutmenge', 'Quantité bonne'],
            'subcontracting.field.goodsBack' => ['Rientrato', 'Goods back', 'Zurückgekommen', 'Retourné'],
            'subcontracting.field.held' => ['Presso il partner', 'At partner', 'Beim Partner', 'Chez le partenaire'],
            'subcontracting.field.heldQty' => ['Quantità presso il partner', 'Quantity at partner', 'Menge beim Partner', 'Quantité chez le partenaire'],
            'subcontracting.field.inboundDocument' => ['Documento di rientro', 'Inbound document', 'Eingangsdokument', 'Document d’entrée'],
            'subcontracting.field.lot' => ['Lotto', 'Lot', 'Charge', 'Lot'],
            'subcontracting.field.materialOut' => ['Materiale uscito', 'Material out', 'Material ausgegangen', 'Matériel sorti'],
            'subcontracting.field.movement' => ['Movimento', 'Movement', 'Bewegung', 'Mouvement'],
            'subcontracting.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'subcontracting.field.open' => ['Aperto', 'Open', 'Offen', 'Ouvert'],
            'subcontracting.field.openOrders' => ['Ordini aperti', 'Open orders', 'Offene Aufträge', 'Ordres ouverts'],
            'subcontracting.field.openQuantity' => ['Quantità ancora attesa', 'Quantity still expected', 'Noch erwartete Menge', 'Quantité encore attendue'],
            'subcontracting.field.order' => ['Ordine', 'Order', 'Auftrag', 'Ordre'],
            'subcontracting.field.orderNumber' => ['Numero ordine', 'Order number', 'Auftragsnummer', 'Numéro d’ordre'],
            'subcontracting.field.outgoingDdt' => ['DDT in uscita', 'Outgoing DDT', 'Ausgehender Lieferschein', 'Bon de livraison sortant'],
            'subcontracting.field.outputProduct' => ['Prodotto lavorato', 'Processed product', 'Bearbeitetes Produkt', 'Produit usiné'],
            'subcontracting.field.partner' => ['Partner', 'Partner', 'Partner', 'Partenaire'],
            'subcontracting.field.perUnit' => ['Per unità', 'Per unit', 'Pro Einheit', 'Par unité'],
            'subcontracting.field.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'subcontracting.field.planned' => ['Pianificato', 'Planned', 'Geplant', 'Planifié'],
            'subcontracting.field.plannedQuantity' => ['Quantità pianificata', 'Planned quantity', 'Geplante Menge', 'Quantité planifiée'],
            'subcontracting.field.product' => ['Prodotto', 'Product', 'Produkt', 'Produit'],
            'subcontracting.field.productionOrder' => ['Ordine di produzione (Mod 064)', 'Production order (Mod 064)', 'Fertigungsauftrag (Mod 064)', 'Ordre de fabrication (Mod 064)'],
            'subcontracting.field.progress' => ['Avanzamento', 'Progress', 'Fortschritt', 'Avancement'],
            'subcontracting.field.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'subcontracting.field.receivedQuantity' => ['Quantità ricevuta', 'Received quantity', 'Erhaltene Menge', 'Quantité reçue'],
            'subcontracting.field.remaining' => ['Residuo presso il partner', 'Remaining at partner', 'Rest beim Partner', 'Reste chez le partenaire'],
            'subcontracting.field.returnUnusedMaterials' => ['Rendi anche il materiale non utilizzato', 'Also return the unused material', 'Unbenutztes Material ebenfalls zurückgeben', 'Retourner aussi le matériel inutilisé'],
            'subcontracting.field.returnedUnused' => ['Reso non utilizzato', 'Returned unused', 'Unbenutzt zurück', 'Retourné inutilisé'],
            'subcontracting.field.routingOperation' => ['Fase del ciclo (es. trattamento termico)', 'Routing operation (e.g. heat treatment)', 'Arbeitsgang (z. B. Wärmebehandlung)', 'Opération de gamme (p. ex. traitement thermique)'],
            'subcontracting.field.scrap' => ['Sfrido', 'Scrap', 'Ausschuss', 'Rebut'],
            'subcontracting.field.scrapQuantity' => ['Quantità scartata', 'Scrapped quantity', 'Ausschussmenge', 'Quantité rebutée'],
            'subcontracting.field.scrapUnits' => ['Pezzi scartati (sfridi dichiarati)', 'Scrapped units (declared scrap)', 'Ausschussstücke (gemeldeter Ausschuss)', 'Pièces rebutées (rebut déclaré)'],
            'subcontracting.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'subcontracting.field.servicePurchaseOrder' => ['Ordine di acquisto del servizio', 'Service purchase order', 'Bestellung der Dienstleistung', 'Commande d’achat du service'],
            'subcontracting.field.sourceWarehouse' => ['Magazzino di partenza', 'Source warehouse', 'Ausgangslager', 'Entrepôt de départ'],
            'subcontracting.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'subcontracting.field.supplier' => ['Terzista (fornitore)', 'Subcontractor (supplier)', 'Lohnfertiger (Lieferant)', 'Sous-traitant (fournisseur)'],
            'subcontracting.field.type' => ['Tipo di lavorazione', 'Subcontracting type', 'Art der Lohnfertigung', 'Type de sous-traitance'],
            'subcontracting.field.unitServiceCost' => ['Costo unitario della lavorazione', 'Unit processing cost', 'Bearbeitungskosten pro Einheit', 'Coût de façonnage unitaire'],
            'subcontracting.field.vendorDdtDate' => ['Data DDT del terzista', 'Subcontractor DDT date', 'Lieferscheindatum des Lohnfertigers', 'Date du bon de livraison du sous-traitant'],
            'subcontracting.field.vendorDdtNumber' => ['Numero DDT del terzista', 'Subcontractor DDT number', 'Lieferscheinnummer des Lohnfertigers', 'Numéro du bon de livraison du sous-traitant'],
            'subcontracting.field.virtualWarehouse' => ['Magazzino virtuale del partner', 'Partner virtual warehouse', 'Virtuelles Lager des Partners', 'Entrepôt virtuel du partenaire'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'subcontracting.action.addLine' => ['Aggiungi riga', 'Add line', 'Zeile hinzufügen', 'Ajouter une ligne'],
            'subcontracting.action.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'subcontracting.action.cancel' => ['Annulla ordine', 'Cancel order', 'Auftrag stornieren', 'Annuler l’ordre'],
            'subcontracting.action.close' => ['Chiudi', 'Close', 'Schließen', 'Fermer'],
            'subcontracting.action.confirm' => ['Conferma ordine', 'Confirm order', 'Auftrag bestätigen', 'Confirmer l’ordre'],
            'subcontracting.action.deliver' => ['Consegna e emetti DDT Reso lavorato', 'Deliver and issue DDT Reso lavorato', 'Liefern und Lieferschein Reso lavorato ausstellen', 'Livrer et émettre le BL Reso lavorato'],
            'subcontracting.action.dispatch' => ['Invia e emetti DDT Conto lavoro', 'Dispatch and issue DDT Conto lavoro', 'Versenden und Lieferschein Conto Lavoro ausstellen', 'Expédier et émettre le BL Conto lavoro'],
            'subcontracting.action.newOrder' => ['Nuovo ordine', 'New order', 'Neuer Auftrag', 'Nouvel ordre'],
            'subcontracting.action.next' => ['Avanti', 'Next', 'Weiter', 'Suivant'],
            'subcontracting.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'subcontracting.action.preview' => ['Anteprima costo e scarico', 'Preview cost and backflush', 'Vorschau Kosten und Rückmeldung', 'Aperçu du coût et du décompte'],
            'subcontracting.action.produce' => ['Registra produzione', 'Record production', 'Produktion erfassen', 'Enregistrer la production'],
            'subcontracting.action.receive' => ['Ricevi lavorato e scarica componenti', 'Receive processed goods and backflush', 'Bearbeitete Ware empfangen und Komponenten rückmelden', 'Recevoir les marchandises usinées et décompter les composants'],
            'subcontracting.action.receiveMaterials' => ['Ricevi materiale del cliente', 'Receive customer material', 'Kundenmaterial empfangen', 'Recevoir le matériel du client'],
            'subcontracting.action.removeLine' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'subcontracting.action.returnUnused' => ['Rendi materiale non utilizzato', 'Return unused material', 'Unbenutztes Material zurückgeben', 'Retourner le matériel inutilisé'],
            'subcontracting.action.save' => ['Crea ordine', 'Create order', 'Auftrag anlegen', 'Créer l’ordre'],

            'subcontracting.modal.deliver' => ['Consegna del lavorato al cliente', 'Deliver the processed goods to the customer', 'Bearbeitete Ware an den Kunden liefern', 'Livrer les marchandises usinées au client'],
            'subcontracting.modal.dispatch' => ['Invio materiali al terzista', 'Dispatch materials to the subcontractor', 'Material an den Lohnfertiger senden', 'Expédition du matériel au sous-traitant'],
            'subcontracting.modal.produce' => ['Produzione sul materiale del cliente', 'Production on the customer material', 'Produktion auf dem Kundenmaterial', 'Production sur le matériel du client'],
            'subcontracting.modal.receive' => ['Ricezione del lavorato e scarico componenti', 'Receive processed goods and backflush', 'Wareneingang bearbeitete Ware und Komponentenrückmeldung', 'Réception des marchandises usinées et décompte des composants'],
            'subcontracting.modal.receiveMaterials' => ['Ricezione materiale del cliente', 'Receive customer material', 'Empfang des Kundenmaterials', 'Réception du matériel du client'],
            'subcontracting.modal.returnUnused' => ['Reso del materiale non utilizzato', 'Return of the unused material', 'Rückgabe des unbenutzten Materials', 'Retour du matériel inutilisé'],

            'subcontracting.confirm.cancelOrder' => ['Annullare l’ordine?', 'Cancel the order?', 'Auftrag stornieren?', 'Annuler l’ordre ?'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'subcontracting.dashboard.subtitle' => ['Merci presso terzisti e materiali dei clienti in lavorazione, con anzianità e termine di rientro di un anno.', 'Goods at subcontractors and customer materials being processed, with aging and the one-year return deadline.', 'Waren bei Lohnfertigern und Kundenmaterial in Bearbeitung, mit Alter und einjähriger Rückgabefrist.', 'Marchandises chez les sous-traitants et matériel client en cours de façonnage, avec ancienneté et délai de retour d’un an.'],
            'subcontracting.orders.subtitle' => ['Conto lavoro passivo verso i terzisti e attivo per i clienti.', 'Passive subcontracting to vendors and active subcontracting for customers.', 'Passive Lohnfertigung bei Lieferanten und aktive Lohnfertigung für Kunden.', 'Sous-traitance passive auprès des fournisseurs et active pour les clients.'],
            'subcontracting.register.subtitle' => ['Registro delle merci in conto lavoro, lavorazione e deposito (Art. 53 DPR 633/72, DPR 441/1997) e riepilogo TPP/TPA Italia–Svizzera.', 'Register of goods in conto lavoro, processing and deposit (Art. 53 DPR 633/72, DPR 441/1997) and the Italy–Switzerland TPP/TPA summary.', 'Register der Waren in Lohnfertigung, Bearbeitung und Verwahrung (Art. 53 DPR 633/72, DPR 441/1997) und TPP/TPA-Übersicht Italien–Schweiz.', 'Registre des marchandises en sous-traitance, façonnage et dépôt (art. 53 DPR 633/72, DPR 441/1997) et récapitulatif TPP/TPA Italie–Suisse.'],
            'subcontracting.wizard.subtitle' => ['Scegli direzione e tipo, il partner, la distinta o la fase del ciclo e il regime doganale.', 'Choose direction and type, the partner, the BOM or routing step and the customs regime.', 'Wählen Sie Richtung und Art, den Partner, die Stückliste oder den Arbeitsgang und das Zollverfahren.', 'Choisissez le sens et le type, le partenaire, la nomenclature ou l’opération de gamme et le régime douanier.'],

            'subcontracting.kpi.activePassive' => ['Ordini passivi attivi', 'Active passive orders', 'Aktive passive Aufträge', 'Ordres passifs actifs'],
            'subcontracting.kpi.activeCustomer' => ['Ordini attivi per clienti', 'Active customer orders', 'Aktive Kundenaufträge', 'Ordres actifs pour clients'],
            'subcontracting.kpi.unitsHeld' => ['Unità presso terzi', 'Units held by third parties', 'Einheiten bei Dritten', 'Unités chez des tiers'],
            'subcontracting.kpi.expiring' => ['In scadenza entro 30 giorni', 'Expiring within 30 days', 'Ablauf innerhalb von 30 Tagen', 'Échéance sous 30 jours'],
            'subcontracting.kpi.overdue' => ['Termini di un anno scaduti', 'Overdue one-year deadlines', 'Abgelaufene Jahresfristen', 'Délais d’un an échus'],
            'subcontracting.kpi.customsRegimes' => ['Regimi TPP/TPA attivi', 'Active TPP/TPA regimes', 'Aktive TPP/TPA-Verfahren', 'Régimes TPP/TPA actifs'],

            'subcontracting.section.stockAtPartners' => ['Giacenze presso i partner', 'Stock at partners', 'Bestände bei Partnern', 'Stocks chez les partenaires'],
            'subcontracting.section.wizardBasics' => ['Dati principali', 'Basics', 'Grunddaten', 'Données principales'],
            'subcontracting.section.wizardSource' => ['Distinta, ciclo e regime doganale', 'BOM, routing and customs regime', 'Stückliste, Arbeitsplan und Zollverfahren', 'Nomenclature, gamme et régime douanier'],
            'subcontracting.section.wizardComponents' => ['Componenti', 'Components', 'Komponenten', 'Composants'],
            'subcontracting.section.summary' => ['Riepilogo', 'Summary', 'Zusammenfassung', 'Récapitulatif'],
            'subcontracting.section.backflush' => ['Scarico componenti e sfridi', 'Component backflush and scrap', 'Komponentenrückmeldung und Ausschuss', 'Décompte des composants et rebut'],
            'subcontracting.section.capitalization' => ['Capitalizzazione del costo di lavorazione', 'Capitalization of the processing cost', 'Aktivierung der Bearbeitungskosten', 'Capitalisation du coût de façonnage'],
            'subcontracting.section.billing' => ['Base della fattura di lavorazione', 'Basis of the processing invoice', 'Grundlage der Bearbeitungsrechnung', 'Base de la facture de façonnage'],

            'subcontracting.step.basics' => ['Dati principali', 'Basics', 'Grunddaten', 'Données principales'],
            'subcontracting.step.source' => ['Distinta e dogana', 'BOM and customs', 'Stückliste und Zoll', 'Nomenclature et douane'],
            'subcontracting.step.components' => ['Componenti', 'Components', 'Komponenten', 'Composants'],
            'subcontracting.step.review' => ['Verifica', 'Review', 'Prüfung', 'Vérification'],

            'subcontracting.tab.overview' => ['Panoramica', 'Overview', 'Übersicht', 'Aperçu'],
            'subcontracting.tab.components' => ['Componenti', 'Components', 'Komponenten', 'Composants'],
            'subcontracting.tab.ledger' => ['Registro dell’ordine', 'Order ledger', 'Auftragsregister', 'Registre de l’ordre'],
            'subcontracting.tab.documents' => ['Documenti collegati', 'Linked documents', 'Verknüpfte Dokumente', 'Documents liés'],
            'subcontracting.tab.register' => ['Registro Art. 53', 'Art. 53 register', 'Register Art. 53', 'Registre art. 53'],
            'subcontracting.tab.crossBorder' => ['TPP / TPA Italia–Svizzera', 'TPP / TPA Italy–Switzerland', 'TPP / TPA Italien–Schweiz', 'TPP / TPA Italie–Suisse'],

            'subcontracting.badge.zeroValue' => ['Valore a bilancio 0,00 (merce del cliente)', 'Balance-sheet value 0.00 (customer goods)', 'Bilanzwert 0,00 (Kundenware)', 'Valeur au bilan 0,00 (marchandise du client)'],

            'subcontracting.countdown.left' => ['{days} giorni al termine', '{days} days left', 'Noch {days} Tage', '{days} jours restants'],
            'subcontracting.countdown.late' => ['Scaduto da {days} giorni', 'Expired {days} days ago', 'Seit {days} Tagen abgelaufen', 'Échu depuis {days} jours'],

            'subcontracting.empty.stock' => ['Nessuna merce presso terzi.', 'No goods held by third parties.', 'Keine Waren bei Dritten.', 'Aucune marchandise chez des tiers.'],
            'subcontracting.empty.orders' => ['Nessun ordine di conto lavoro.', 'No subcontracting orders.', 'Keine Lohnfertigungsaufträge.', 'Aucun ordre de sous-traitance.'],
            'subcontracting.empty.register' => ['Nessun movimento nel registro.', 'No register entries.', 'Keine Registereinträge.', 'Aucune écriture au registre.'],
            'subcontracting.empty.crossBorder' => ['Nessuna lavorazione TPP/TPA.', 'No TPP/TPA jobs.', 'Keine TPP/TPA-Aufträge.', 'Aucune opération TPP/TPA.'],

            'subcontracting.preview.componentsValue' => ['Valore dei componenti consumati e scartati', 'Value of the components consumed and scrapped', 'Wert der verbrauchten und ausgeschiedenen Komponenten', 'Valeur des composants consommés et rebutés'],
            'subcontracting.preview.serviceFee' => ['Costo del servizio di lavorazione', 'Processing service fee', 'Kosten der Bearbeitungsdienstleistung', 'Coût du service de façonnage'],
            'subcontracting.preview.totalCost' => ['Costo totale capitalizzato', 'Total capitalized cost', 'Aktivierte Gesamtkosten', 'Coût total capitalisé'],
            'subcontracting.preview.unitCost' => ['Costo unitario del lavorato', 'Unit cost of the processed goods', 'Stückkosten der bearbeiteten Ware', 'Coût unitaire des marchandises usinées'],

            'subcontracting.hint.dispatch' => ['I componenti passano dal magazzino aziendale al magazzino virtuale di {partner} e viene emesso il DDT Conto lavoro (nessun trasferimento di proprietà). Il termine di rientro è di un anno dalla data del DDT.', 'The components move from the company warehouse to the virtual warehouse of {partner} and the DDT Conto lavoro is issued (no transfer of ownership). The return deadline is one year from the DDT date.', 'Die Komponenten wechseln vom Firmenlager ins virtuelle Lager von {partner} und der Lieferschein Conto Lavoro wird ausgestellt (kein Eigentumsübergang). Die Rückgabefrist beträgt ein Jahr ab Lieferscheindatum.', 'Les composants passent de l’entrepôt de la société à l’entrepôt virtuel de {partner} et le bon de livraison Conto lavoro est émis (sans transfert de propriété). Le délai de retour est d’un an à compter de la date du bon.'],
            'subcontracting.hint.returnUnused' => ['Il materiale non lavorato torna da {partner} al magazzino aziendale con il DDT Reso da terzista e il registro viene chiuso per le quantità rese.', 'The unworked material comes back from {partner} to the company warehouse with the DDT Reso da terzista and the register is closed for the returned quantities.', 'Das unbearbeitete Material kommt von {partner} ins Firmenlager zurück, mit Lieferschein Reso da terzista; das Register wird für die zurückgegebenen Mengen geschlossen.', 'Le matériel non usiné revient de {partner} à l’entrepôt de la société avec le bon Reso da terzista et le registre est clos pour les quantités retournées.'],
            'subcontracting.hint.receiveMaterials' => ['Il materiale di {partner} entra nel magazzino merci del cliente, separato dal patrimonio aziendale, a valore zero. Indica il DDT del cliente per il registro.', 'The material of {partner} enters the segregated customer-goods warehouse at zero value, apart from the company assets. Enter the customer DDT for the register.', 'Das Material von {partner} geht zum Wert null in das getrennte Kundenwarenlager, abseits des Firmenvermögens. Erfassen Sie den Kundenlieferschein für das Register.', 'Le matériel de {partner} entre dans l’entrepôt des marchandises du client, séparé de l’actif de la société, à valeur zéro. Indiquez le bon de livraison du client pour le registre.'],
            'subcontracting.hint.deliver' => ['Il lavorato torna al cliente con il DDT Reso lavorato (nessun trasferimento di proprietà) e si prepara la base della fattura di lavorazione.', 'The processed goods go back to the customer with the DDT Reso lavorato (no transfer of ownership) and the basis of the processing invoice is prepared.', 'Die bearbeitete Ware geht mit Lieferschein Reso lavorato an den Kunden zurück (kein Eigentumsübergang) und die Grundlage der Bearbeitungsrechnung wird vorbereitet.', 'Les marchandises usinées retournent au client avec le bon Reso lavorato (sans transfert de propriété) et la base de la facture de façonnage est préparée.'],
            'subcontracting.hint.receive' => ['Indica i pezzi buoni e gli scarti dichiarati dal terzista: ancora attesi {open}. I componenti si scaricano in automatico dal magazzino virtuale.', 'Enter the good units and the scrap declared by the subcontractor: {open} still expected. The components are backflushed automatically from the virtual warehouse.', 'Erfassen Sie die Gutstücke und den vom Lohnfertiger gemeldeten Ausschuss: noch erwartet {open}. Die Komponenten werden automatisch aus dem virtuellen Lager rückgemeldet.', 'Indiquez les pièces bonnes et le rebut déclaré par le sous-traitant : {open} encore attendus. Les composants sont décomptés automatiquement de l’entrepôt virtuel.'],
            'subcontracting.hint.produce' => ['Registra i pezzi buoni e gli scarti prodotti con il materiale del cliente: ancora attesi {open}. I componenti si consumano dal magazzino del cliente.', 'Record the good and scrapped units made with the customer material: {open} still expected. The components are consumed from the customer warehouse.', 'Erfassen Sie die mit dem Kundenmaterial gefertigten Gut- und Ausschussstücke: noch erwartet {open}. Die Komponenten werden aus dem Kundenlager verbraucht.', 'Enregistrez les pièces bonnes et rebutées fabriquées avec le matériel du client : {open} encore attendus. Les composants sont consommés de l’entrepôt du client.'],
            'subcontracting.hint.backflush' => ['Lasciando vuoto un campo vale il consumo previsto dalla distinta o dal ciclo; inserisci la quantità effettiva per correggerlo.', 'An empty field uses the consumption planned by the BOM or routing; enter the actual quantity to override it.', 'Ein leeres Feld verwendet den Verbrauch laut Stückliste oder Arbeitsplan; geben Sie die tatsächliche Menge ein, um ihn zu überschreiben.', 'Un champ vide reprend la consommation prévue par la nomenclature ou la gamme ; saisissez la quantité réelle pour la corriger.'],
            'subcontracting.hint.componentsFromBom' => ['I componenti vengono presi dalla distinta base scelta (e dalla fase del ciclo, per la singola lavorazione).', 'The components come from the chosen BOM (and from the routing step for a single operation).', 'Die Komponenten stammen aus der gewählten Stückliste (und bei einem Einzelarbeitsgang aus dem Arbeitsgang).', 'Les composants proviennent de la nomenclature choisie (et de l’opération de gamme pour une opération unique).'],
            'subcontracting.hint.componentsManual' => ['Senza distinta, indica i componenti e la quantità per unità prodotta.', 'Without a BOM, enter the components and the quantity per unit made.', 'Ohne Stückliste erfassen Sie die Komponenten und die Menge pro gefertigter Einheit.', 'Sans nomenclature, saisissez les composants et la quantité par unité fabriquée.'],
            'subcontracting.hint.virtualWarehouse' => ['Il magazzino virtuale del partner viene creato in automatico se manca (Mod 051).', 'The partner virtual warehouse is created automatically when missing (Mod 051).', 'Das virtuelle Lager des Partners wird automatisch angelegt, falls es fehlt (Mod 051).', 'L’entrepôt virtuel du partenaire est créé automatiquement s’il manque (Mod 051).'],
            'subcontracting.hint.zeroValue' => ['La merce del cliente resta di sua proprietà: entra in un magazzino separato a valore zero e non entra mai nella valutazione di magazzino della società.', 'The customer goods stay its property: they enter a separate warehouse at zero value and never enter the inventory valuation of the company.', 'Die Kundenware bleibt dessen Eigentum: Sie geht zum Wert null in ein getrenntes Lager und fließt nie in die Lagerbewertung der Gesellschaft ein.', 'La marchandise du client reste sa propriété : elle entre dans un entrepôt séparé à valeur zéro et n’entre jamais dans la valorisation des stocks de la société.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'subcontracting.message.saved' => ['Ordine di conto lavoro creato.', 'Subcontracting order created.', 'Lohnfertigungsauftrag angelegt.', 'Ordre de sous-traitance créé.'],
            'subcontracting.message.confirmed' => ['Ordine confermato.', 'Order confirmed.', 'Auftrag bestätigt.', 'Ordre confirmé.'],
            'subcontracting.message.cancelled' => ['Ordine annullato.', 'Order cancelled.', 'Auftrag storniert.', 'Ordre annulé.'],
            'subcontracting.message.dispatch' => ['Materiale inviato e DDT emesso.', 'Material dispatched and DDT issued.', 'Material versandt und Lieferschein ausgestellt.', 'Matériel expédié et bon de livraison émis.'],
            'subcontracting.message.receive' => ['Ricezione registrata.', 'Receipt recorded.', 'Wareneingang erfasst.', 'Réception enregistrée.'],
            'subcontracting.message.returnUnused' => ['Materiale non utilizzato reso e DDT emesso.', 'Unused material returned and DDT issued.', 'Unbenutztes Material zurückgegeben und Lieferschein ausgestellt.', 'Matériel inutilisé retourné et bon de livraison émis.'],
            'subcontracting.message.receiveMaterials' => ['Materiale del cliente ricevuto a valore zero.', 'Customer material received at zero value.', 'Kundenmaterial zum Wert null empfangen.', 'Matériel du client reçu à valeur zéro.'],
            'subcontracting.message.produce' => ['Produzione registrata.', 'Production recorded.', 'Produktion erfasst.', 'Production enregistrée.'],
            'subcontracting.message.deliver' => ['Lavorato consegnato e DDT emesso.', 'Processed goods delivered and DDT issued.', 'Bearbeitete Ware geliefert und Lieferschein ausgestellt.', 'Marchandises usinées livrées et bon de livraison émis.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dictionary(string $locale): array
    {
        /** @var array<string, mixed> $dictionary */
        $dictionary = require lang_path($locale.'/subcontracting.php');

        return $dictionary;
    }

    /**
     * One text of the backend dictionary in the four languages.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private static function backend(string $path): array
    {
        $texts = array_map(static function (string $locale) use ($path): string {
            $value = self::dictionary($locale);

            foreach (explode('.', $path, 2) as $segment) {
                $value = $value[$segment] ?? null;
            }

            return (string) $value;
        }, self::Locales);

        return [$texts[0], $texts[1], $texts[2], $texts[3]];
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
