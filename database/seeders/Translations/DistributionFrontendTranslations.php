<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of distribution, multi-warehouse and transfers (DRP, Issue #271): the overview, the warehouse network and its lanes, the
 * transfers with goods in transit, the replenishment rules and proposals, the time-phased DRP matrix, consignment / 3PL and the sales
 * channel availability (ATP), plus the codes the API reports (distribution.error.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal and logistics acronyms (DRP, ATP, 3PL, DDT, SKU, ETA) stay untranslated.
 */
final class DistributionFrontendTranslations
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
            'NAV.DISTRIBUTION' => ['Distribuzione e DRP', 'Distribution & DRP', 'Distribution und DRP', 'Distribution et DRP'],
            'NAV.DISTRIBUTION_OVERVIEW' => ['Panoramica distribuzione', 'Distribution overview', 'Distributionsübersicht', 'Vue d’ensemble de la distribution'],
            'NAV.DISTRIBUTION_NETWORK' => ['Rete di magazzini', 'Warehouse network', 'Lagernetzwerk', 'Réseau d’entrepôts'],
            'NAV.DISTRIBUTION_TRANSFERS' => ['Trasferimenti', 'Transfers', 'Umlagerungen', 'Transferts'],
            'NAV.DISTRIBUTION_IN_TRANSIT' => ['Merce in transito', 'Goods in transit', 'Ware im Transit', 'Marchandises en transit'],
            'NAV.DISTRIBUTION_REPLENISHMENT' => ['Regole di riordino', 'Replenishment rules', 'Nachschubregeln', 'Règles de réapprovisionnement'],
            'NAV.DISTRIBUTION_PROPOSALS' => ['Proposte e bilanciamento', 'Proposals & balancing', 'Vorschläge und Ausgleich', 'Propositions et équilibrage'],
            'NAV.DISTRIBUTION_DRP' => ['Pianificazione DRP', 'DRP planning', 'DRP-Planung', 'Planification DRP'],
            'NAV.DISTRIBUTION_CONSIGNMENT' => ['Conto deposito e 3PL', 'Consignment & 3PL', 'Konsignation und 3PL', 'Dépôt-vente et 3PL'],
            'NAV.DISTRIBUTION_CHANNELS' => ['Canali di vendita', 'Sales channels', 'Verkaufskanäle', 'Canaux de vente'],
            'NAV.DISTRIBUTION_ATP' => ['Disponibilità per canale', 'Availability by channel', 'Verfügbarkeit je Kanal', 'Disponibilité par canal'],
            'PAGE_TITLE.DISTRIBUTION_OVERVIEW' => ['Distribuzione, multi-magazzino e trasferimenti', 'Distribution, multi-warehouse and transfers', 'Distribution, Mehrlager und Umlagerungen', 'Distribution, multi-entrepôts et transferts'],
            'PAGE_TITLE.DISTRIBUTION_NETWORK' => ['Rete di magazzini e rotte di distribuzione', 'Warehouse network and distribution lanes', 'Lagernetzwerk und Distributionsrouten', 'Réseau d’entrepôts et routes de distribution'],
            'PAGE_TITLE.DISTRIBUTION_TRANSFERS' => ['Trasferimenti tra magazzini', 'Inter-warehouse transfers', 'Umlagerungen zwischen Lagern', 'Transferts entre entrepôts'],
            'PAGE_TITLE.DISTRIBUTION_IN_TRANSIT' => ['Merce in transito', 'Goods in transit', 'Ware im Transit', 'Marchandises en transit'],
            'PAGE_TITLE.DISTRIBUTION_REPLENISHMENT' => ['Regole di riordino per magazzino e articolo', 'Replenishment rules by warehouse and item', 'Nachschubregeln je Lager und Artikel', 'Règles de réapprovisionnement par entrepôt et article'],
            'PAGE_TITLE.DISTRIBUTION_PROPOSALS' => ['Proposte di riordino e bilanciamento delle scorte', 'Replenishment and stock balancing proposals', 'Nachschub- und Bestandsausgleichsvorschläge', 'Propositions de réapprovisionnement et d’équilibrage des stocks'],
            'PAGE_TITLE.DISTRIBUTION_DRP' => ['Pianificazione dei fabbisogni di distribuzione (DRP)', 'Distribution requirements planning (DRP)', 'Distributionsbedarfsplanung (DRP)', 'Planification des besoins de distribution (DRP)'],
            'PAGE_TITLE.DISTRIBUTION_CONSIGNMENT' => ['Conto deposito, magazzini di terzi e 3PL', 'Consignment, third-party warehouses and 3PL', 'Konsignation, Fremdlager und 3PL', 'Dépôt-vente, entrepôts tiers et 3PL'],
            'PAGE_TITLE.DISTRIBUTION_CHANNELS' => ['Canali di vendita e regole di allocazione', 'Sales channels and allocation rules', 'Verkaufskanäle und Allokationsregeln', 'Canaux de vente et règles d’allocation'],
            'PAGE_TITLE.DISTRIBUTION_ATP' => ['Stock disponibile per canale (ATP)', 'Available stock by channel (ATP)', 'Verfügbarer Bestand je Kanal (ATP)', 'Stock disponible par canal (ATP)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'distribution.warehouseRole.internal' => ['Magazzino interno', 'Internal warehouse', 'Internes Lager', 'Entrepôt interne'],
            'distribution.warehouseRole.retail_pos' => ['Punto vendita', 'Retail point of sale', 'Verkaufsstelle', 'Point de vente'],
            'distribution.warehouseRole.transit' => ['Transito', 'In transit', 'Transit', 'Transit'],
            'distribution.warehouseRole.third_party_3pl' => ['Magazzino di terzi (3PL)', 'Third-party warehouse (3PL)', 'Fremdlager (3PL)', 'Entrepôt tiers (3PL)'],
            'distribution.warehouseRole.consignment_out' => ['Conto deposito presso terzi', 'Consignment at third parties', 'Konsignation bei Dritten', 'Dépôt chez des tiers'],
            'distribution.warehouseRole.consignment_in' => ['Conto deposito da terzi', 'Consignment from third parties', 'Konsignation von Dritten', 'Dépôt de tiers'],
            'distribution.policy.min_max' => ['Min/Max', 'Min/Max', 'Min/Max', 'Min/Max'],
            'distribution.policy.reorder_point_fixed_qty' => ['Punto di riordino e quantità fissa', 'Reorder point and fixed quantity', 'Bestellpunkt und feste Menge', 'Point de commande et quantité fixe'],
            'distribution.policy.periodic_review' => ['Revisione periodica', 'Periodic review', 'Periodische Überprüfung', 'Révision périodique'],
            'distribution.policy.demand_driven_drp' => ['DRP guidato dalla domanda', 'Demand-driven DRP', 'Nachfragegesteuertes DRP', 'DRP piloté par la demande'],
            'distribution.allocationMode.shared_pool' => ['Pool condiviso', 'Shared pool', 'Gemeinsamer Pool', 'Pool partagé'],
            'distribution.allocationMode.percentage' => ['Percentuale', 'Percentage', 'Prozentsatz', 'Pourcentage'],
            'distribution.allocationMode.fixed_reserve' => ['Riserva fissa', 'Fixed reserve', 'Feste Reserve', 'Réserve fixe'],
            'distribution.allocationMode.priority_rank' => ['Priorità', 'Priority rank', 'Prioritätsrang', 'Rang de priorité'],
            'distribution.proposalType.replenishment' => ['Riordino', 'Replenishment', 'Nachschub', 'Réapprovisionnement'],
            'distribution.proposalType.balancing' => ['Bilanciamento', 'Balancing', 'Ausgleich', 'Équilibrage'],
            'distribution.proposalStatus.pending' => ['Da valutare', 'Pending', 'Offen', 'En attente'],
            'distribution.proposalStatus.approved' => ['Approvata', 'Approved', 'Genehmigt', 'Approuvée'],
            'distribution.proposalStatus.rejected' => ['Rifiutata', 'Rejected', 'Abgelehnt', 'Rejetée'],
            'distribution.proposalStatus.converted' => ['Convertita in trasferimento', 'Converted to transfer', 'In Umlagerung umgewandelt', 'Convertie en transfert'],
            'distribution.proposalStatus.shortfall' => ['Carenza al magazzino centrale', 'Shortfall at the hub', 'Fehlmenge im Zentrallager', 'Manque à l’entrepôt central'],
            'distribution.transportMode.road' => ['Strada', 'Road', 'Strasse', 'Route'],
            'distribution.transportMode.rail' => ['Ferrovia', 'Rail', 'Bahn', 'Rail'],
            'distribution.transportMode.air' => ['Aereo', 'Air', 'Luft', 'Aérien'],
            'distribution.transportMode.sea' => ['Mare', 'Sea', 'See', 'Mer'],
            'distribution.transportMode.courier' => ['Corriere', 'Courier', 'Kurier', 'Coursier'],
            'distribution.transportMode.internal' => ['Trasporto interno', 'Internal transport', 'Interner Transport', 'Transport interne'],
            'distribution.bucketType.daily' => ['Giornaliero', 'Daily', 'Täglich', 'Quotidien'],
            'distribution.bucketType.weekly' => ['Settimanale', 'Weekly', 'Wöchentlich', 'Hebdomadaire'],
            'distribution.channelType.b2b_portal' => ['Portale B2B', 'B2B portal', 'B2B-Portal', 'Portail B2B'],
            'distribution.channelType.ecommerce' => ['E-commerce', 'E-commerce', 'E-Commerce', 'E-commerce'],
            'distribution.channelType.retail_pos' => ['Punto vendita', 'Retail POS', 'Verkaufsstelle', 'Point de vente'],
            'distribution.channelType.marketplace' => ['Marketplace', 'Marketplace', 'Marktplatz', 'Place de marché'],
            'distribution.channelType.direct_sales' => ['Vendita diretta', 'Direct sales', 'Direktvertrieb', 'Vente directe'],
            'distribution.channelType.custom' => ['Canale personalizzato', 'Custom channel', 'Eigener Kanal', 'Canal personnalisé'],
            'distribution.settlementTrigger.on_consumption' => ['Al consumo', 'On consumption', 'Bei Verbrauch', 'À la consommation'],
            'distribution.settlementTrigger.monthly_report' => ['Rendiconto mensile', 'Monthly report', 'Monatsmeldung', 'Rapport mensuel'],
            'distribution.settlementTrigger.on_return' => ['Alla restituzione', 'On return', 'Bei Rückgabe', 'Au retour'],
            'distribution.billingFrequency.weekly' => ['Settimanale', 'Weekly', 'Wöchentlich', 'Hebdomadaire'],
            'distribution.billingFrequency.monthly' => ['Mensile', 'Monthly', 'Monatlich', 'Mensuelle'],
            'distribution.billingFrequency.quarterly' => ['Trimestrale', 'Quarterly', 'Vierteljährlich', 'Trimestrielle'],
            'distribution.transferStatus.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'distribution.transferStatus.approved' => ['Approvato', 'Approved', 'Genehmigt', 'Approuvé'],
            'distribution.transferStatus.in_transit' => ['In transito', 'In transit', 'Im Transit', 'En transit'],
            'distribution.transferStatus.partially_received' => ['Ricevuto in parte', 'Partially received', 'Teilweise empfangen', 'Partiellement reçu'],
            'distribution.transferStatus.received' => ['Ricevuto', 'Received', 'Empfangen', 'Reçu'],
            'distribution.transferStatus.discrepancy_pending' => ['Discrepanza da risolvere', 'Discrepancy pending', 'Differenz offen', 'Écart à résoudre'],
            'distribution.transferStatus.closed' => ['Chiuso', 'Closed', 'Abgeschlossen', 'Clôturé'],
            'distribution.transferStatus.cancelled' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],
            'distribution.tab.all' => ['Tutti', 'All', 'Alle', 'Tous'],
            'distribution.resolution.keep_in_transit' => ['Lascia in transito (secondo collo)', 'Keep in transit (second parcel)', 'Im Transit belassen (zweites Paket)', 'Garder en transit (second colis)'],
            'distribution.resolution.write_off' => ['Stralcia come perdita o danno in transito', 'Write off as transit loss or damage', 'Als Transitverlust oder -schaden ausbuchen', 'Passer en perte ou casse en transit'],
            'distribution.resolution.return_to_source' => ['Rendi al magazzino di origine', 'Return to the source warehouse', 'An das Quelllager zurückgeben', 'Retourner à l’entrepôt d’origine'],
            'distribution.resolution.carrier_claim' => ['Reclamo al vettore', 'Carrier claim', 'Reklamation beim Frachtführer', 'Réclamation au transporteur'],
            'distribution.alertType.stockout_risk' => ['Rischio di rottura di stock', 'Stockout risk', 'Gefahr eines Lagerengpasses', 'Risque de rupture de stock'],
            'distribution.alertType.excess_stock' => ['Scorta in eccesso', 'Excess stock', 'Überbestand', 'Stock excédentaire'],
            'distribution.alertType.late_inbound' => ['Arrivo in ritardo', 'Late inbound', 'Verspäteter Eingang', 'Arrivage en retard'],
            'distribution.drpRow.gross_requirements' => ['Fabbisogno lordo', 'Gross requirements', 'Bruttobedarf', 'Besoins bruts'],
            'distribution.drpRow.scheduled_receipts' => ['Ricevimenti programmati', 'Scheduled receipts', 'Geplante Zugänge', 'Réceptions planifiées'],
            'distribution.drpRow.projected_available' => ['Disponibilità proiettata', 'Projected available balance', 'Verfügbarer Bestand (Prognose)', 'Disponible projeté'],
            'distribution.drpRow.net_requirements' => ['Fabbisogno netto', 'Net requirements', 'Nettobedarf', 'Besoins nets'],
            'distribution.drpRow.planned_receipts' => ['Ricevimenti pianificati', 'Planned order receipts', 'Geplante Auftragseingänge', 'Réceptions d’ordres planifiées'],
            'distribution.drpRow.planned_releases' => ['Rilasci pianificati', 'Planned order releases', 'Geplante Auftragsfreigaben', 'Lancements d’ordres planifiés'],
            'distribution.direction.out' => ['Presso terzi', 'Held at third parties', 'Bei Dritten', 'Chez des tiers'],
            'distribution.direction.in' => ['Da terzi', 'Held from third parties', 'Von Dritten', 'De tiers'],
            'distribution.settlementStatus.open' => ['Aperta', 'Open', 'Offen', 'Ouverte'],
            'distribution.settlementStatus.invoiced' => ['Fatturata', 'Invoiced', 'Fakturiert', 'Facturée'],
            'distribution.reconciliationStatus.balanced' => ['Allineata', 'Balanced', 'Ausgeglichen', 'Équilibrée'],
            'distribution.reconciliationStatus.discrepancy' => ['Con discrepanze', 'Discrepancy', 'Mit Differenzen', 'Avec écarts'],
            'distribution.calc.onHand' => ['Giacenza', 'On hand', 'Bestand', 'Stock physique'],
            'distribution.calc.inTransitInbound' => ['In arrivo in transito', 'In-transit inbound', 'Eingehend im Transit', 'Entrant en transit'],
            'distribution.calc.reserved' => ['Riservato', 'Reserved', 'Reserviert', 'Réservé'],
            'distribution.calc.safetyStock' => ['Scorta di sicurezza', 'Safety stock', 'Sicherheitsbestand', 'Stock de sécurité'],
            'distribution.calc.reorderPoint' => ['Punto di riordino', 'Reorder point', 'Bestellpunkt', 'Point de commande'],
            'distribution.calc.maxStock' => ['Scorta massima', 'Maximum stock', 'Maximalbestand', 'Stock maximum'],
            'distribution.calc.projectedDemand' => ['Domanda prevista nel lead time', 'Projected demand over the lead time', 'Prognostizierte Nachfrage in der Wiederbeschaffungszeit', 'Demande prévue sur le délai'],
            'distribution.calc.netPosition' => ['Posizione netta', 'Net position', 'Nettoposition', 'Position nette'],
            'distribution.calc.reason.below_reorder_point' => ['Sotto il punto di riordino', 'Below the reorder point', 'Unter dem Meldebestand', 'Sous le point de commande'],
            'distribution.calc.reason.below_target_level' => ['Sotto il livello obiettivo della revisione periodica', 'Below the periodic review target level', 'Unter dem Zielbestand der periodischen Überprüfung', 'Sous le niveau cible de la révision périodique'],
            'distribution.calc.reason.below_safety_stock' => ['Sotto la scorta di sicurezza', 'Below the safety stock', 'Unter dem Sicherheitsbestand', 'Sous le stock de sécurité'],
            'distribution.calc.step.target_level' => ['Livello obiettivo', 'Target level', 'Zielbestand', 'Niveau cible'],
            'distribution.calc.step.needed_quantity' => ['Quantità necessaria', 'Needed quantity', 'Benötigte Menge', 'Quantité nécessaire'],
            'distribution.calc.step.rounded_quantity' => ['Quantità arrotondata al multiplo di trasferimento', 'Quantity rounded to the transfer multiple', 'Auf das Umlagerungsvielfache gerundete Menge', 'Quantité arrondie au multiple de transfert'],
            'distribution.calc.step.balanced_from_peer' => ['Coperta da un altro magazzino con eccedenza', 'Covered by a peer warehouse with excess stock', 'Von einem Lager mit Überbestand gedeckt', 'Couverte par un entrepôt en surstock'],
            'distribution.calc.step.hub_available' => ['Disponibile presso il magazzino di rifornimento', 'Available at the supplying hub', 'Verfügbar im Versorgungslager', 'Disponible à l’entrepôt d’approvisionnement'],
            'distribution.calc.step.shortfall' => ['Quantità non coperta', 'Uncovered quantity', 'Nicht gedeckte Menge', 'Quantité non couverte'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'distribution.error.generic' => ['Operazione non riuscita. Riprova.', 'The operation failed. Try again.', 'Der Vorgang ist fehlgeschlagen. Bitte erneut versuchen.', 'L’opération a échoué. Réessayez.'],
            'distribution.error.forbidden' => ['Non hai i permessi per questa operazione.', 'You are not allowed to do this.', 'Dafür fehlt die Berechtigung.', 'Vous n’avez pas les droits pour cette opération.'],
            'distribution.error.not_found' => ['Elemento non trovato.', 'The record was not found.', 'Der Datensatz wurde nicht gefunden.', 'Élément introuvable.'],
            'distribution.error.same_warehouse' => ['Origine e destinazione devono essere magazzini diversi.', 'Source and destination must be different warehouses.', 'Quelle und Ziel müssen verschiedene Lager sein.', 'L’origine et la destination doivent être des entrepôts différents.'],
            'distribution.error.warehouse_blocked' => ['Il magazzino è bloccato.', 'The warehouse is blocked.', 'Das Lager ist gesperrt.', 'L’entrepôt est bloqué.'],
            'distribution.error.warehouse_counting' => ['Il magazzino è in inventario fisico.', 'The warehouse is being counted.', 'Das Lager wird gerade inventarisiert.', 'L’entrepôt est en cours d’inventaire.'],
            'distribution.error.insufficient_stock' => ['La giacenza disponibile non copre la quantità richiesta.', 'The available stock does not cover the requested quantity.', 'Der verfügbare Bestand deckt die angeforderte Menge nicht.', 'Le stock disponible ne couvre pas la quantité demandée.'],
            'distribution.error.invalid_status_transition' => ['Il trasferimento non è in uno stato che consente questa azione.', 'The transfer is not in a status that allows this action.', 'Die Umlagerung hat keinen Status, der diese Aktion erlaubt.', 'Le transfert n’est pas dans un état qui permet cette action.'],
            'distribution.error.cannot_cancel_in_transit' => ['Un trasferimento in transito non si annulla: va ricevuto o reso.', 'A transfer in transit cannot be cancelled: receive or return it.', 'Eine Umlagerung im Transit kann nicht storniert werden: empfangen oder zurückgeben.', 'Un transfert en transit ne s’annule pas : il se réceptionne ou se retourne.'],
            'distribution.error.receipt_exceeds_shipped' => ['La quantità ricevuta supera quella spedita.', 'The received quantity exceeds the shipped quantity.', 'Die empfangene Menge übersteigt die versendete Menge.', 'La quantité reçue dépasse la quantité expédiée.'],
            'distribution.error.resolution_required' => ['Indica come risolvere la differenza o il danno.', 'Say how the shortage or damage is resolved.', 'Geben Sie an, wie Fehlmenge oder Schaden bereinigt werden.', 'Indiquez comment résoudre l’écart ou la casse.'],
            'distribution.error.lane_exists' => ['Esiste già una rotta tra questi due magazzini.', 'A lane between these two warehouses already exists.', 'Zwischen diesen beiden Lagern besteht bereits eine Route.', 'Une route existe déjà entre ces deux entrepôts.'],
            'distribution.error.rule_exists' => ['Esiste già una regola per questo magazzino e articolo.', 'A rule for this warehouse and item already exists.', 'Für dieses Lager und diesen Artikel besteht bereits eine Regel.', 'Une règle existe déjà pour cet entrepôt et cet article.'],
            'distribution.error.proposal_not_pending' => ['La proposta non è più da valutare.', 'The proposal is no longer pending.', 'Der Vorschlag ist nicht mehr offen.', 'La proposition n’est plus en attente.'],
            'distribution.error.channel_exists' => ['Esiste già un canale con questo codice.', 'A channel with this code already exists.', 'Ein Kanal mit diesem Code existiert bereits.', 'Un canal avec ce code existe déjà.'],
            'distribution.error.invalid_allocation' => ['La regola di allocazione non è coerente.', 'The allocation rule is not consistent.', 'Die Allokationsregel ist nicht stimmig.', 'La règle d’allocation n’est pas cohérente.'],
            'distribution.error.not_a_consignment_warehouse' => ['Il magazzino non è in conto deposito.', 'The warehouse is not a consignment warehouse.', 'Das Lager ist kein Konsignationslager.', 'L’entrepôt n’est pas un entrepôt de dépôt-vente.'],
            'distribution.error.settlement_exceeds_stock' => ['Il consumo supera la giacenza in conto deposito.', 'The consumption exceeds the consignment stock.', 'Der Verbrauch übersteigt den Konsignationsbestand.', 'La consommation dépasse le stock en dépôt.'],
            'distribution.error.not_a_3pl_warehouse' => ['Il magazzino non è di un 3PL.', 'The warehouse is not a 3PL warehouse.', 'Das Lager gehört keinem 3PL.', 'L’entrepôt n’est pas un entrepôt 3PL.'],
            'distribution.error.warehouse_inactive' => ['Il magazzino non è attivo.', 'The warehouse is not active.', 'Das Lager ist nicht aktiv.', 'L’entrepôt n’est pas actif.'],
            'distribution.error.rule_violation' => ['L’operazione non è consentita dalle regole di magazzino.', 'The warehouse rules do not allow this operation.', 'Die Lagerregeln erlauben diesen Vorgang nicht.', 'Les règles d’entrepôt n’autorisent pas cette opération.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'distribution.field.actions' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'distribution.field.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'distribution.field.inactive' => ['Non attivo', 'Inactive', 'Inaktiv', 'Inactif'],
            'distribution.field.yes' => ['Sì', 'Yes', 'Ja', 'Oui'],
            'distribution.field.no' => ['No', 'No', 'Nein', 'Non'],
            'distribution.field.alertType' => ['Tipo di avviso', 'Alert type', 'Warnungstyp', 'Type d’alerte'],
            'distribution.field.allocationMode' => ['Modalità di allocazione', 'Allocation mode', 'Allokationsmodus', 'Mode d’allocation'],
            'distribution.field.allocationPercentage' => ['Percentuale allocata', 'Allocated percentage', 'Zugeteilter Prozentsatz', 'Pourcentage alloué'],
            'distribution.field.allocationValue' => ['Allocazione', 'Allocation', 'Allokation', 'Allocation'],
            'distribution.field.atp' => ['ATP (disponibile da promettere)', 'ATP (available to promise)', 'ATP (verfügbar zur Zusage)', 'ATP (disponible à promettre)'],
            'distribution.field.autoApprove' => ['Approva subito il trasferimento', 'Approve the transfer right away', 'Umlagerung sofort genehmigen', 'Approuver le transfert immédiatement'],
            'distribution.field.billingFrequency' => ['Frequenza di fatturazione', 'Billing frequency', 'Abrechnungsfrequenz', 'Fréquence de facturation'],
            'distribution.field.blocked' => ['Bloccato', 'Blocked', 'Gesperrt', 'Bloqué'],
            'distribution.field.bucket' => ['Periodo', 'Bucket', 'Zeitraum', 'Période'],
            'distribution.field.bucketType' => ['Intervallo', 'Bucket size', 'Zeitraster', 'Intervalle'],
            'distribution.field.carrier' => ['Vettore', 'Carrier', 'Frachtführer', 'Transporteur'],
            'distribution.field.channel' => ['Canale', 'Channel', 'Kanal', 'Canal'],
            'distribution.field.channelType' => ['Tipo di canale', 'Channel type', 'Kanaltyp', 'Type de canal'],
            'distribution.field.code' => ['Codice', 'Code', 'Code', 'Code'],
            'distribution.field.costPerUnit' => ['Costo per unità', 'Cost per unit', 'Kosten pro Einheit', 'Coût par unité'],
            'distribution.field.counting' => ['In inventario', 'Being counted', 'In Inventur', 'En inventaire'],
            'distribution.field.countsInValuation' => ['Conta nella valorizzazione', 'Counts in valuation', 'Zählt in der Bewertung', 'Compte dans la valorisation'],
            'distribution.field.damaged' => ['Danneggiata', 'Damaged', 'Beschädigt', 'Endommagée'],
            'distribution.field.daysHeld' => ['Giorni in deposito', 'Days held', 'Tage im Lager', 'Jours en dépôt'],
            'distribution.field.ddtReference' => ['Riferimento DDT', 'DDT reference', 'DDT-Referenz', 'Référence DDT'],
            'distribution.field.defaultTransit' => ['Magazzino di transito predefinito', 'Default transit warehouse', 'Standard-Transitlager', 'Entrepôt de transit par défaut'],
            'distribution.field.destination' => ['Destinazione', 'Destination', 'Ziel', 'Destination'],
            'distribution.field.differences' => ['Differenze', 'Differences', 'Differenzen', 'Écarts'],
            'distribution.field.direction' => ['Direzione', 'Direction', 'Richtung', 'Sens'],
            'distribution.field.exclude3pl' => ['Escludi 3PL', 'Exclude 3PL', '3PL ausschliessen', 'Exclure le 3PL'],
            'distribution.field.excludeConsignment' => ['Escludi conto deposito', 'Exclude consignment', 'Konsignation ausschliessen', 'Exclure le dépôt-vente'],
            'distribution.field.expectedArrival' => ['Arrivo previsto (ETA)', 'Expected arrival (ETA)', 'Voraussichtliche Ankunft (ETA)', 'Arrivée prévue (ETA)'],
            'distribution.field.expectedTo' => ['Arrivo previsto entro', 'Expected by', 'Erwartet bis', 'Arrivée prévue avant le'],
            'distribution.field.externalQuantity' => ['Quantità del 3PL', 'Quantity at the 3PL', 'Menge beim 3PL', 'Quantité chez le 3PL'],
            'distribution.field.externalReference' => ['Riferimento esterno', 'External reference', 'Externe Referenz', 'Référence externe'],
            'distribution.field.finalReceipt' => ['Ricevimento finale (chiude le quantità residue)', 'Final receipt (closes the remaining quantity)', 'Schlusseingang (schliesst die Restmenge)', 'Réception finale (clôture le reliquat)'],
            'distribution.field.fixedQuantity' => ['Quantità fissa', 'Fixed quantity', 'Feste Menge', 'Quantité fixe'],
            'distribution.field.fixedReserve' => ['Riserva fissa', 'Fixed reserve', 'Feste Reserve', 'Réserve fixe'],
            'distribution.field.flags' => ['Stato', 'Flags', 'Kennzeichen', 'Indicateurs'],
            'distribution.field.horizon' => ['Orizzonte (periodi)', 'Horizon (buckets)', 'Horizont (Perioden)', 'Horizon (périodes)'],
            'distribution.field.includeInFreeStock' => ['Include nello stock libero', 'Include in free stock', 'In freiem Bestand enthalten', 'Inclure dans le stock libre'],
            'distribution.field.includeInTransit' => ['Include il transito', 'Include in transit', 'Transit einbeziehen', 'Inclure le transit'],
            'distribution.field.insured' => ['Assicurato', 'Insured', 'Versichert', 'Assuré'],
            'distribution.field.inTransitInbound' => ['In arrivo', 'In-transit inbound', 'Eingehend', 'Entrant'],
            'distribution.field.item' => ['Articolo (SKU)', 'Item (SKU)', 'Artikel (SKU)', 'Article (SKU)'],
            'distribution.field.leadTimeDays' => ['Lead time (giorni)', 'Lead time (days)', 'Wiederbeschaffungszeit (Tage)', 'Délai (jours)'],
            'distribution.field.leadTimeHours' => ['Lead time (ore)', 'Lead time (hours)', 'Wiederbeschaffungszeit (Stunden)', 'Délai (heures)'],
            'distribution.field.lines' => ['Righe', 'Lines', 'Positionen', 'Lignes'],
            'distribution.field.lot' => ['Lotto', 'Lot', 'Charge', 'Lot'],
            'distribution.field.maxConsignmentDays' => ['Giorni massimi di deposito', 'Maximum consignment days', 'Maximale Konsignationstage', 'Jours de dépôt maximum'],
            'distribution.field.maxStock' => ['Scorta massima', 'Maximum stock', 'Maximalbestand', 'Stock maximum'],
            'distribution.field.minTransferQty' => ['Quantità minima di trasferimento', 'Minimum transfer quantity', 'Mindestumlagerungsmenge', 'Quantité minimale de transfert'],
            'distribution.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'distribution.field.noProfile' => ['Nessun profilo', 'No profile', 'Kein Profil', 'Aucun profil'],
            'distribution.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'distribution.field.onHand' => ['Giacenza', 'On hand', 'Bestand', 'Stock physique'],
            'distribution.field.openingBalance' => ['Saldo iniziale', 'Opening balance', 'Anfangsbestand', 'Solde initial'],
            'distribution.field.originalQuantity' => ['Quantità originale', 'Original quantity', 'Ursprüngliche Menge', 'Quantité d’origine'],
            'distribution.field.outstanding' => ['Ancora attesa', 'Outstanding', 'Noch erwartet', 'Encore attendue'],
            'distribution.field.overdue' => ['In ritardo', 'Overdue', 'Überfällig', 'En retard'],
            'distribution.field.overdueOnly' => ['Solo spedizioni in ritardo', 'Overdue shipments only', 'Nur überfällige Sendungen', 'Seulement les envois en retard'],
            'distribution.field.parentWarehouse' => ['Magazzino padre (hub)', 'Parent warehouse (hub)', 'Übergeordnetes Lager (Hub)', 'Entrepôt parent (hub)'],
            'distribution.field.partner' => ['Partner terzo', 'Third-party partner', 'Drittpartner', 'Partenaire tiers'],
            'distribution.field.policy' => ['Politica di riordino', 'Replenishment policy', 'Nachschubstrategie', 'Politique de réapprovisionnement'],
            'distribution.field.priority' => ['Priorità', 'Priority', 'Priorität', 'Priorité'],
            'distribution.field.priorityRank' => ['Rango di priorità', 'Priority rank', 'Prioritätsrang', 'Rang de priorité'],
            'distribution.field.proposalType' => ['Tipo di proposta', 'Proposal type', 'Vorschlagstyp', 'Type de proposition'],
            'distribution.field.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'distribution.field.quantityInTransit' => ['Quantità in transito', 'Quantity in transit', 'Menge im Transit', 'Quantité en transit'],
            'distribution.field.received' => ['ricevuti', 'received', 'empfangen', 'reçus'],
            'distribution.field.receivedGood' => ['Ricevuta integra', 'Received in good condition', 'In Ordnung empfangen', 'Reçue en bon état'],
            'distribution.field.reconciledAt' => ['Riconciliato il', 'Reconciled on', 'Abgeglichen am', 'Rapproché le'],
            'distribution.field.reference' => ['Riferimento', 'Reference', 'Referenz', 'Référence'],
            'distribution.field.reorderPoint' => ['Punto di riordino', 'Reorder point', 'Bestellpunkt', 'Point de commande'],
            'distribution.field.requestedDate' => ['Data richiesta', 'Requested date', 'Wunschdatum', 'Date demandée'],
            'distribution.field.reserved' => ['Riservato', 'Reserved', 'Reserviert', 'Réservé'],
            'distribution.field.resolution' => ['Risoluzione della differenza', 'Discrepancy resolution', 'Behebung der Differenz', 'Résolution de l’écart'],
            'distribution.field.reviewCycleDays' => ['Ciclo di revisione (giorni)', 'Review cycle (days)', 'Überprüfungszyklus (Tage)', 'Cycle de révision (jours)'],
            'distribution.field.ringFenced' => ['Riservato al canale', 'Ring-fenced', 'Kanalreserviert', 'Réservé au canal'],
            'distribution.field.role' => ['Ruolo del magazzino', 'Warehouse role', 'Lagerrolle', 'Rôle de l’entrepôt'],
            'distribution.field.route' => ['Rotta', 'Route', 'Route', 'Route'],
            'distribution.field.run' => ['Esecuzione', 'Run', 'Lauf', 'Exécution'],
            'distribution.field.safetyBuffer' => ['Buffer di sicurezza', 'Safety buffer', 'Sicherheitspuffer', 'Tampon de sécurité'],
            'distribution.field.safetyStock' => ['Scorta di sicurezza', 'Safety stock', 'Sicherheitsbestand', 'Stock de sécurité'],
            'distribution.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'distribution.field.settledAt' => ['Data del consuntivo', 'Settlement date', 'Abrechnungsdatum', 'Date du décompte'],
            'distribution.field.settlementNumber' => ['Numero consuntivo', 'Settlement number', 'Abrechnungsnummer', 'Numéro de décompte'],
            'distribution.field.settlementTrigger' => ['Innesco del consuntivo', 'Settlement trigger', 'Abrechnungsauslöser', 'Déclencheur du décompte'],
            'distribution.field.shortage' => ['Mancante', 'Shortage', 'Fehlmenge', 'Manquant'],
            'distribution.field.shortfall' => ['Carenza', 'Shortfall', 'Fehlmenge', 'Manque'],
            'distribution.field.source' => ['Origine', 'Source', 'Quelle', 'Origine'],
            'distribution.field.sourceAvailable' => ['Disponibile all’origine', 'Available at the source', 'Verfügbar an der Quelle', 'Disponible à l’origine'],
            'distribution.field.sourceWarehouse' => ['Magazzino di rifornimento', 'Supplying warehouse', 'Liefernes Lager', 'Entrepôt d’approvisionnement'],
            'distribution.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'distribution.field.stockoutDate' => ['Rottura di stock prevista', 'Projected stockout', 'Voraussichtlicher Engpass', 'Rupture prévue'],
            'distribution.field.suggestedQuantity' => ['Quantità proposta', 'Suggested quantity', 'Vorgeschlagene Menge', 'Quantité proposée'],
            'distribution.field.targetDaysOfSupply' => ['Giorni di copertura obiettivo', 'Target days of supply', 'Ziel-Reichweite (Tage)', 'Jours de couverture cible'],
            'distribution.field.total' => ['Totale', 'Total', 'Total', 'Total'],
            'distribution.field.tracking' => ['Tracking', 'Tracking', 'Sendungsverfolgung', 'Suivi'],
            'distribution.field.trackingNumber' => ['Numero di tracking', 'Tracking number', 'Sendungsnummer', 'Numéro de suivi'],
            'distribution.field.transfer' => ['Trasferimento', 'Transfer', 'Umlagerung', 'Transfert'],
            'distribution.field.transferMultiple' => ['Multiplo di trasferimento', 'Transfer multiple', 'Umlagerungsvielfaches', 'Multiple de transfert'],
            'distribution.field.transportMode' => ['Modalità di trasporto', 'Transport mode', 'Transportart', 'Mode de transport'],
            'distribution.field.unitCost' => ['Costo unitario', 'Unit cost', 'Stückkosten', 'Coût unitaire'],
            'distribution.field.unitPrice' => ['Prezzo unitario', 'Unit price', 'Stückpreis', 'Prix unitaire'],
            'distribution.field.uom' => ['UM', 'UoM', 'ME', 'UM'],
            'distribution.field.value' => ['Valore', 'Value', 'Wert', 'Valeur'],
            'distribution.field.warehouse' => ['Magazzino', 'Warehouse', 'Lager', 'Entrepôt'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'distribution.action.addLine' => ['Aggiungi riga', 'Add line', 'Position hinzufügen', 'Ajouter une ligne'],
            'distribution.action.approve' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'distribution.action.approveAsApproved' => ['Approva ({count}) e crea trasferimenti approvati', 'Approve ({count}) into approved transfers', '({count}) genehmigen und als genehmigte Umlagerungen anlegen', 'Approuver ({count}) en transferts approuvés'],
            'distribution.action.approveAsDraft' => ['Crea trasferimenti in bozza', 'Create draft transfers', 'Entwurfs-Umlagerungen anlegen', 'Créer des transferts en brouillon'],
            'distribution.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'distribution.action.cancelTransfer' => ['Annulla trasferimento', 'Cancel transfer', 'Umlagerung stornieren', 'Annuler le transfert'],
            'distribution.action.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'distribution.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'distribution.action.dispatch' => ['Spedisci', 'Dispatch', 'Versenden', 'Expédier'],
            'distribution.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'distribution.action.editProfile' => ['Modifica profilo', 'Edit profile', 'Profil bearbeiten', 'Modifier le profil'],
            'distribution.action.generateProposals' => ['Genera proposte', 'Generate proposals', 'Vorschläge erzeugen', 'Générer les propositions'],
            'distribution.action.inspectCalculation' => ['Calcolo', 'Calculation', 'Berechnung', 'Calcul'],
            'distribution.action.newChannel' => ['Nuovo canale', 'New channel', 'Neuer Kanal', 'Nouveau canal'],
            'distribution.action.newChannelRule' => ['Nuova regola di allocazione', 'New allocation rule', 'Neue Allokationsregel', 'Nouvelle règle d’allocation'],
            'distribution.action.newLane' => ['Nuova rotta', 'New lane', 'Neue Route', 'Nouvelle route'],
            'distribution.action.newRule' => ['Nuova regola', 'New rule', 'Neue Regel', 'Nouvelle règle'],
            'distribution.action.newTransfer' => ['Nuovo trasferimento', 'New transfer', 'Neue Umlagerung', 'Nouveau transfert'],
            'distribution.action.openDrp' => ['Apri pianificazione DRP', 'Open DRP planning', 'DRP-Planung öffnen', 'Ouvrir la planification DRP'],
            'distribution.action.receive' => ['Ricevi', 'Receive', 'Empfangen', 'Réceptionner'],
            'distribution.action.reconcile3pl' => ['Riconcilia stock 3PL', 'Reconcile 3PL stock', '3PL-Bestand abgleichen', 'Rapprocher le stock 3PL'],
            'distribution.action.recordSettlement' => ['Registra consuntivo', 'Record settlement', 'Abrechnung erfassen', 'Enregistrer un décompte'],
            'distribution.action.reject' => ['Rifiuta', 'Reject', 'Ablehnen', 'Rejeter'],
            'distribution.action.removeLine' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'distribution.action.returnConsignment' => ['Reso da conto deposito', 'Return from consignment', 'Konsignationsrückgabe', 'Retour de dépôt-vente'],
            'distribution.action.reviewProposals' => ['Valuta le proposte', 'Review proposals', 'Vorschläge prüfen', 'Examiner les propositions'],
            'distribution.action.runDrp' => ['Esegui DRP', 'Run DRP', 'DRP ausführen', 'Lancer le DRP'],
            'distribution.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'distribution.action.showRules' => ['Regole', 'Rules', 'Regeln', 'Règles'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'distribution.section.alerts' => ['Avvisi del DRP', 'DRP alerts', 'DRP-Warnungen', 'Alertes DRP'],
            'distribution.section.atpBreakdown' => ['Dettaglio per articolo', 'Breakdown by item', 'Aufschlüsselung je Artikel', 'Détail par article'],
            'distribution.section.atpMatrix' => ['Matrice ATP articolo per canale', 'ATP matrix, item by channel', 'ATP-Matrix Artikel je Kanal', 'Matrice ATP article par canal'],
            'distribution.section.calculation' => ['Calcolo della proposta', 'Proposal calculation', 'Berechnung des Vorschlags', 'Calcul de la proposition'],
            'distribution.section.channel' => ['Canale di vendita', 'Sales channel', 'Verkaufskanal', 'Canal de vente'],
            'distribution.section.channelRule' => ['Regola di allocazione', 'Allocation rule', 'Allokationsregel', 'Règle d’allocation'],
            'distribution.section.channelRules' => ['Magazzini e regole di allocazione del canale', 'Warehouses and allocation rules of the channel', 'Lager und Allokationsregeln des Kanals', 'Entrepôts et règles d’allocation du canal'],
            'distribution.section.consignmentStock' => ['Stock in conto deposito', 'Consignment stock', 'Konsignationsbestand', 'Stock en dépôt-vente'],
            'distribution.section.dispatch' => ['Spedizione: vettore e DDT', 'Dispatch: carrier and DDT', 'Versand: Frachtführer und DDT', 'Expédition : transporteur et DDT'],
            'distribution.section.lane' => ['Rotta di distribuzione', 'Distribution lane', 'Distributionsroute', 'Route de distribution'],
            'distribution.section.lanes' => ['Rotte di distribuzione', 'Distribution lanes', 'Distributionsrouten', 'Routes de distribution'],
            'distribution.section.newTransfer' => ['Nuovo trasferimento tra magazzini', 'New inter-warehouse transfer', 'Neue Umlagerung zwischen Lagern', 'Nouveau transfert entre entrepôts'],
            'distribution.section.overdueShipments' => ['Spedizioni in ritardo', 'Overdue shipments', 'Überfällige Sendungen', 'Envois en retard'],
            'distribution.section.profile' => ['Profilo del magazzino nella rete', 'Warehouse profile in the network', 'Lagerprofil im Netzwerk', 'Profil de l’entrepôt dans le réseau'],
            'distribution.section.receive' => ['Ricevimento: integro, danneggiato, mancante', 'Receipt: good, damaged, missing', 'Wareneingang: in Ordnung, beschädigt, fehlend', 'Réception : conforme, endommagé, manquant'],
            'distribution.section.reconciliation' => ['Riconciliazione stock 3PL', '3PL stock reconciliation', '3PL-Bestandsabgleich', 'Rapprochement du stock 3PL'],
            'distribution.section.return' => ['Reso al magazzino interno', 'Return to an internal warehouse', 'Rückgabe an ein internes Lager', 'Retour vers un entrepôt interne'],
            'distribution.section.rule' => ['Regola di riordino', 'Replenishment rule', 'Nachschubregel', 'Règle de réapprovisionnement'],
            'distribution.section.settlement' => ['Consuntivo di consumo', 'Consumption settlement', 'Verbrauchsabrechnung', 'Décompte de consommation'],
            'distribution.section.settlements' => ['Consuntivi', 'Settlements', 'Abrechnungen', 'Décomptes'],
            'distribution.section.warehouses' => ['Magazzini', 'Warehouses', 'Lager', 'Entrepôts'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'distribution.confirm.approveTransfer' => ['Approvare il trasferimento?', 'Approve the transfer?', 'Umlagerung genehmigen?', 'Approuver le transfert ?'],
            'distribution.confirm.cancelTransfer' => ['Annullare il trasferimento?', 'Cancel the transfer?', 'Umlagerung stornieren?', 'Annuler le transfert ?'],
            'distribution.confirm.deleteChannel' => ['Eliminare il canale?', 'Delete the channel?', 'Kanal löschen?', 'Supprimer le canal ?'],
            'distribution.confirm.deleteChannelRule' => ['Eliminare la regola di allocazione?', 'Delete the allocation rule?', 'Allokationsregel löschen?', 'Supprimer la règle d’allocation ?'],
            'distribution.confirm.deleteLane' => ['Eliminare la rotta?', 'Delete the lane?', 'Route löschen?', 'Supprimer la route ?'],
            'distribution.confirm.deleteRule' => ['Eliminare la regola?', 'Delete the rule?', 'Regel löschen?', 'Supprimer la règle ?'],
            'distribution.confirm.rejectProposal' => ['Rifiutare la proposta?', 'Reject the proposal?', 'Vorschlag ablehnen?', 'Rejeter la proposition ?'],
            'distribution.empty.atp' => ['Nessun articolo con disponibilità per canale.', 'No item with channel availability.', 'Kein Artikel mit Kanalverfügbarkeit.', 'Aucun article avec disponibilité par canal.'],
            'distribution.empty.channelRules' => ['Nessun magazzino assegnato al canale.', 'No warehouse assigned to the channel.', 'Dem Kanal ist kein Lager zugewiesen.', 'Aucun entrepôt affecté au canal.'],
            'distribution.empty.channels' => ['Nessun canale di vendita.', 'No sales channel.', 'Kein Verkaufskanal.', 'Aucun canal de vente.'],
            'distribution.empty.consignment' => ['Nessuno stock in conto deposito.', 'No consignment stock.', 'Kein Konsignationsbestand.', 'Aucun stock en dépôt-vente.'],
            'distribution.empty.drpGrid' => ['Nessuna riga per la selezione.', 'No row for the selection.', 'Keine Zeile für die Auswahl.', 'Aucune ligne pour la sélection.'],
            'distribution.empty.drpRuns' => ['Nessuna esecuzione del DRP: avviane una.', 'No DRP run yet: start one.', 'Noch kein DRP-Lauf: starten Sie einen.', 'Aucune exécution DRP : lancez-en une.'],
            'distribution.empty.inTransit' => ['Nessuna merce in transito.', 'No goods in transit.', 'Keine Ware im Transit.', 'Aucune marchandise en transit.'],
            'distribution.empty.lanes' => ['Nessuna rotta di distribuzione.', 'No distribution lane.', 'Keine Distributionsroute.', 'Aucune route de distribution.'],
            'distribution.empty.overdue' => ['Nessuna spedizione in ritardo.', 'No overdue shipment.', 'Keine überfällige Sendung.', 'Aucun envoi en retard.'],
            'distribution.empty.proposals' => ['Nessuna proposta.', 'No proposal.', 'Kein Vorschlag.', 'Aucune proposition.'],
            'distribution.empty.reconciliations' => ['Nessuna riconciliazione 3PL.', 'No 3PL reconciliation.', 'Kein 3PL-Abgleich.', 'Aucun rapprochement 3PL.'],
            'distribution.empty.rules' => ['Nessuna regola di riordino.', 'No replenishment rule.', 'Keine Nachschubregel.', 'Aucune règle de réapprovisionnement.'],
            'distribution.empty.settlements' => ['Nessun consuntivo.', 'No settlement.', 'Keine Abrechnung.', 'Aucun décompte.'],
            'distribution.empty.transfers' => ['Nessun trasferimento.', 'No transfer.', 'Keine Umlagerung.', 'Aucun transfert.'],
            'distribution.empty.warehouses' => ['Nessun magazzino.', 'No warehouse.', 'Kein Lager.', 'Aucun entrepôt.'],
            'distribution.hint.availability' => ['Disponibile {available} (giacenza {onHand}, riservato {reserved})', 'Available {available} (on hand {onHand}, reserved {reserved})', 'Verfügbar {available} (Bestand {onHand}, reserviert {reserved})', 'Disponible {available} (stock {onHand}, réservé {reserved})'],
            'distribution.hint.laneLeadTime' => ['La rotta impiega {days} giorni e {hours} ore.', 'The lane takes {days} days and {hours} hours.', 'Die Route dauert {days} Tage und {hours} Stunden.', 'La route prend {days} jours et {hours} heures.'],
            'distribution.hint.pickItem' => ['Scegli un articolo per vedere la disponibilità per canale e magazzino.', 'Pick an item to see its availability by channel and warehouse.', 'Wählen Sie einen Artikel, um die Verfügbarkeit je Kanal und Lager zu sehen.', 'Choisissez un article pour voir sa disponibilité par canal et entrepôt.'],
            'distribution.hint.resolution' => ['Le unità mancanti o danneggiate non restano orfane: la risoluzione scelta le chiude.', 'Missing or damaged units never stay orphaned: the chosen resolution closes them.', 'Fehlende oder beschädigte Einheiten bleiben nie verwaist: die gewählte Behebung schliesst sie ab.', 'Les unités manquantes ou endommagées ne restent jamais orphelines : la résolution choisie les clôture.'],
            'distribution.hint.shortfallProcurement' => ['Il magazzino centrale non ha stock sufficiente: valuta un acquisto o la pianificazione MRP.', 'The hub does not have enough stock: consider a purchase or MRP planning.', 'Das Zentrallager hat nicht genug Bestand: Einkauf oder MRP-Planung prüfen.', 'L’entrepôt central n’a pas assez de stock : envisagez un achat ou la planification MRP.'],
            'distribution.kpi.activeTransfers' => ['Trasferimenti attivi', 'Active transfers', 'Aktive Umlagerungen', 'Transferts actifs'],
            'distribution.kpi.consignmentValue' => ['Valore stock in conto deposito', 'Consignment stock value', 'Wert des Konsignationsbestands', 'Valeur du stock en dépôt-vente'],
            'distribution.kpi.overdueShipments' => ['{count} spedizioni in ritardo', '{count} overdue shipments', '{count} überfällige Sendungen', '{count} envois en retard'],
            'distribution.kpi.pendingProposals' => ['Proposte da valutare', 'Pending proposals', 'Offene Vorschläge', 'Propositions en attente'],
            'distribution.kpi.stockoutAlerts' => ['Avvisi di rottura di stock', 'Stockout alerts', 'Engpasswarnungen', 'Alertes de rupture de stock'],
            'distribution.kpi.unitsInTransit' => ['Unità in transito', 'Units in transit', 'Einheiten im Transit', 'Unités en transit'],
            'distribution.kpi.valueInTransit' => ['Valore in transito', 'Value in transit', 'Wert im Transit', 'Valeur en transit'],
            'distribution.notification.dispatched' => ['Trasferimento spedito: la merce è in transito.', 'Transfer dispatched: the goods are in transit.', 'Umlagerung versendet: die Ware ist im Transit.', 'Transfert expédié : la marchandise est en transit.'],
            'distribution.notification.generated' => ['Proposte generate: {created} di riordino, {balancing} di bilanciamento, {shortfalls} carenze.', 'Proposals generated: {created} replenishment, {balancing} balancing, {shortfalls} shortfalls.', 'Vorschläge erzeugt: {created} Nachschub, {balancing} Ausgleich, {shortfalls} Fehlmengen.', 'Propositions générées : {created} de réapprovisionnement, {balancing} d’équilibrage, {shortfalls} manques.'],
            'distribution.notification.received' => ['Ricevimento registrato.', 'Receipt recorded.', 'Wareneingang erfasst.', 'Réception enregistrée.'],
            'distribution.notification.saved' => ['Salvato.', 'Saved.', 'Gespeichert.', 'Enregistré.'],
            'distribution.notification.shortfalls' => ['{count} proposte non coperte dal magazzino centrale.', '{count} proposals the hub cannot cover.', '{count} Vorschläge, die das Zentrallager nicht decken kann.', '{count} propositions que l’entrepôt central ne couvre pas.'],
            'distribution.notification.transferCreated' => ['Trasferimento creato.', 'Transfer created.', 'Umlagerung angelegt.', 'Transfert créé.'],
            'distribution.notification.transfersCreated' => ['{count} trasferimenti creati dalle proposte.', '{count} transfers created from the proposals.', '{count} Umlagerungen aus den Vorschlägen angelegt.', '{count} transferts créés à partir des propositions.'],
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
