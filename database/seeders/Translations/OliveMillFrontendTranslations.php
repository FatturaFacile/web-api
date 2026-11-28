<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the olive oil mill and grain mill module (Mod 297, Issue #543): crop deliveries and the weighbridge, the milling schedule and
 * cycles, the molenda (in-kind) settlement wizard, the synoptic tank map and the SIAN telematic oil register with the by-product register.
 * Menus, page titles, tabs, table headers, form labels, statuses, enumerations, SIAN causal descriptions, tooltips, notices and the codes the API
 * reports (oliveMill.error.<code>). Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French
 * text fr-CH. Placeholders are {name}. Legal acronyms and procedure names (SIAN, CUAA, DOP, IGP, EVO, P/IVA, CF, DDT) stay untranslated.
 */
final class OliveMillFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::causals(),
            self::errors(),
            self::fields(),
            self::columns(),
            self::actions(),
            self::sections(),
            self::messages(),
            self::labels(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.OLIVE_MILL' => ['Frantoio & Molino (Mod 297)', 'Olive oil mill & grain mill (Mod 297)', 'Ölmühle & Getreidemühle (Mod 297)', 'Huilerie & moulin à grains (Mod 297)'],
            'NAV.OLIVE_MILL_DASHBOARD' => ['Torre di controllo campagna', 'Campaign control tower', 'Kampagnen-Leitstand', 'Tour de contrôle de la campagne'],
            'NAV.OLIVE_MILL_DELIVERIES' => ['Conferimenti e pesa', 'Deliveries and weighbridge', 'Anlieferungen und Waage', 'Livraisons et pesée'],
            'NAV.OLIVE_MILL_SCHEDULE' => ['Calendario e frangitura', 'Schedule and milling', 'Terminplan und Pressung', 'Planning et broyage'],
            'NAV.OLIVE_MILL_SETTLEMENTS' => ['Liquidazione e molenda', 'Settlement and molenda', 'Abrechnung und Molenda', 'Règlement et molenda'],
            'NAV.OLIVE_MILL_TANKS' => ['Mappa silos e cisterne', 'Tank and silo map', 'Silo- und Tankkarte', 'Carte des silos et cuves'],
            'NAV.OLIVE_MILL_SIAN' => ['Registro SIAN e sottoprodotti', 'SIAN register and by-products', 'SIAN-Register und Nebenprodukte', 'Registre SIAN et sous-produits'],
            'PAGE_TITLE.OLIVE_MILL_DASHBOARD' => ['Frantoio e molino: torre di controllo della campagna', 'Olive and grain mill: campaign control tower', 'Öl- und Getreidemühle: Kampagnen-Leitstand', 'Huilerie et moulin : tour de contrôle de la campagne'],
            'PAGE_TITLE.OLIVE_MILL_DELIVERIES' => ['Conferimenti di olive e cereali e pesa', 'Olive and grain deliveries and weighbridge', 'Anlieferungen von Oliven und Getreide und Waage', 'Livraisons d’olives et de céréales et pesée'],
            'PAGE_TITLE.OLIVE_MILL_SCHEDULE' => ['Calendario prenotazioni e cicli di frangitura', 'Booking calendar and milling cycles', 'Buchungskalender und Pressvorgänge', 'Calendrier des réservations et cycles de broyage'],
            'PAGE_TITLE.OLIVE_MILL_SETTLEMENTS' => ['Liquidazione della molitura e pagamento in natura (molenda)', 'Milling settlement and payment in kind (molenda)', 'Abrechnung der Lohnverarbeitung und Naturalzahlung (Molenda)', 'Règlement du travail à façon et paiement en nature (molenda)'],
            'PAGE_TITLE.OLIVE_MILL_TANKS' => ['Mappa sinottica di silos e cisterne', 'Synoptic map of tanks and silos', 'Synoptische Karte der Silos und Tanks', 'Carte synoptique des silos et cuves'],
            'PAGE_TITLE.OLIVE_MILL_SIAN' => ['Registro telematico olio SIAN e sottoprodotti', 'SIAN telematic oil register and by-products', 'Telematisches SIAN-Ölregister und Nebenprodukte', 'Registre télématique de l’huile SIAN et sous-produits'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'oliveMill.processingMode.CONTO_TERZI' => ['Conto terzi', 'Third-party milling', 'Lohnverarbeitung', 'Travail à façon'],
            'oliveMill.processingMode.CONTO_PROPRIO' => ['Conto proprio', 'Own account', 'Eigene Rechnung', 'Pour compte propre'],
            'oliveMill.commodity.OLIVE' => ['Olive', 'Olives', 'Oliven', 'Olives'],
            'oliveMill.commodity.CEREALI' => ['Cereali', 'Grain', 'Getreide', 'Céréales'],
            'oliveMill.certification.EVO' => ['Extra vergine', 'Extra virgin', 'Nativ extra', 'Extra vierge'],
            'oliveMill.certification.VERGINE' => ['Vergine', 'Virgin', 'Nativ', 'Vierge'],
            'oliveMill.certification.LAMPANTE' => ['Lampante', 'Lampante', 'Lampantöl', 'Lampante'],
            'oliveMill.certification.DOP' => ['DOP', 'DOP', 'DOP', 'DOP'],
            'oliveMill.certification.IGP' => ['IGP', 'IGP', 'IGP', 'IGP'],
            'oliveMill.certification.BIOLOGICO' => ['Biologico', 'Organic', 'Bio', 'Biologique'],
            'oliveMill.certification.CONVENZIONALE' => ['Convenzionale', 'Conventional', 'Konventionell', 'Conventionnel'],
            'oliveMill.category.EVO' => ['EVO', 'EVO', 'EVO', 'EVO'],
            'oliveMill.category.VERGINE' => ['Vergine', 'Virgin', 'Nativ', 'Vierge'],
            'oliveMill.category.LAMPANTE' => ['Lampante', 'Lampante', 'Lampantöl', 'Lampante'],
            'oliveMill.category.DOP' => ['DOP', 'DOP', 'DOP', 'DOP'],
            'oliveMill.category.IGP' => ['IGP', 'IGP', 'IGP', 'IGP'],
            'oliveMill.category.BIO' => ['BIO', 'ORGANIC', 'BIO', 'BIO'],
            'oliveMill.origin.ITALIA' => ['Italia', 'Italy', 'Italien', 'Italie'],
            'oliveMill.origin.UE' => ['Unione europea', 'European Union', 'Europäische Union', 'Union européenne'],
            'oliveMill.origin.EXTRA_UE' => ['Extra UE', 'Non-EU', 'Nicht-EU', 'Hors UE'],
            'oliveMill.origin.DOP_SPECIFICA' => ['Origine DOP specifica', 'Specific DOP origin', 'Spezifische DOP-Herkunft', 'Origine DOP spécifique'],
            'oliveMill.deliveryStatus.BOOKED' => ['Prenotato', 'Booked', 'Gebucht', 'Réservé'],
            'oliveMill.deliveryStatus.WEIGHED' => ['Pesato', 'Weighed', 'Gewogen', 'Pesé'],
            'oliveMill.deliveryStatus.IN_HOPPER' => ['In tramoggia', 'In the hopper', 'Im Trichter', 'Dans la trémie'],
            'oliveMill.deliveryStatus.MILLED' => ['Molito', 'Milled', 'Verarbeitet', 'Broyé'],
            'oliveMill.deliveryStatus.SETTLED' => ['Liquidato', 'Settled', 'Abgerechnet', 'Réglé'],
            'oliveMill.deliveryStatus.CANCELLED' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],
            'oliveMill.cycleStatus.IN_PROGRESS' => ['In corso', 'In progress', 'In Bearbeitung', 'En cours'],
            'oliveMill.cycleStatus.COMPLETED' => ['Completato', 'Completed', 'Abgeschlossen', 'Terminé'],
            'oliveMill.cycleStatus.SETTLED' => ['Liquidato', 'Settled', 'Abgerechnet', 'Réglé'],
            'oliveMill.smsStatus.SENT' => ['SMS inviato', 'SMS sent', 'SMS gesendet', 'SMS envoyé'],
            'oliveMill.smsStatus.FAILED' => ['SMS non consegnato', 'SMS failed', 'SMS fehlgeschlagen', 'SMS non envoyé'],
            'oliveMill.sianStatus.DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'oliveMill.sianStatus.VALIDATED' => ['Validata', 'Validated', 'Validiert', 'Validée'],
            'oliveMill.sianStatus.EXPORTED' => ['Esportata', 'Exported', 'Exportiert', 'Exportée'],
            'oliveMill.sianStatus.TRANSMITTED_SIAN' => ['Trasmessa al SIAN', 'Sent to SIAN', 'An SIAN übermittelt', 'Transmise au SIAN'],
            'oliveMill.sianStatus.ACK_OK' => ['Ricevuta SIAN OK', 'SIAN receipt OK', 'SIAN-Quittung OK', 'Accusé SIAN OK'],
            'oliveMill.sianStatus.ACK_ERROR' => ['Errore SIAN', 'SIAN error', 'SIAN-Fehler', 'Erreur SIAN'],
            'oliveMill.tariffMode.PER_QUINTAL_OLIVES' => ['€ per quintale di olive', '€ per quintal of olives', '€ pro Doppelzentner Oliven', '€ par quintal d’olives'],
            'oliveMill.tariffMode.PER_KG_OIL' => ['€ per kg di olio', '€ per kg of oil', '€ pro kg Öl', '€ par kg d’huile'],
            'oliveMill.tariffMode.FLAT_FEE' => ['Tariffa forfettaria', 'Flat fee', 'Pauschalpreis', 'Forfait'],
            'oliveMill.tariffMode.PURE_PERCENTAGE_MOLENDA' => ['Molenda in percentuale pura', 'Pure molenda percentage', 'Reine Molenda in Prozent', 'Molenda en pourcentage pur'],
            'oliveMill.settlementMode.CASH_OR_BANK' => ['Pagamento in denaro o banca', 'Cash or bank payment', 'Zahlung in bar oder per Bank', 'Paiement en espèces ou par banque'],
            'oliveMill.settlementMode.FULL_IN_KIND_MOLENDA' => ['Interamente in natura (molenda)', 'Fully in kind (molenda)', 'Vollständig in Natur (Molenda)', 'Entièrement en nature (molenda)'],
            'oliveMill.settlementMode.HYBRID_MOLENDA' => ['Misto: molenda + saldo in denaro', 'Hybrid: molenda + cash balance', 'Gemischt: Molenda + Restbetrag in bar', 'Mixte : molenda + solde en espèces'],
            'oliveMill.cashDirection.farmer_pays' => ['Il conferente paga al frantoio', 'The farmer pays the mill', 'Der Landwirt zahlt an die Mühle', 'L’agriculteur paie le moulin'],
            'oliveMill.cashDirection.mill_pays' => ['Il frantoio paga al conferente', 'The mill pays the farmer', 'Die Mühle zahlt an den Landwirt', 'Le moulin paie l’agriculteur'],
            'oliveMill.cashDirection.none' => ['Compensazione esatta', 'Exact offset', 'Exakte Verrechnung', 'Compensation exacte'],
            'oliveMill.byproduct.SANSA_VERGINE' => ['Sansa vergine', 'Virgin pomace', 'Olivenkuchen', 'Grignons'],
            'oliveMill.byproduct.NOCCIOLINO' => ['Nocciolino', 'Olive pits', 'Olivenkerne', 'Noyaux d’olive'],
            'oliveMill.byproduct.ACQUE_VEGETAZIONE' => ['Acque di vegetazione', 'Vegetation water', 'Vegetationswasser', 'Margines'],
            'oliveMill.byproduct.CRUSCA' => ['Crusca', 'Bran', 'Kleie', 'Son'],
            'oliveMill.destination.SANSIFICIO' => ['Sansificio (con DDT)', 'Pomace mill (with DDT)', 'Nachpresserei (mit DDT)', 'Usine de grignons (avec DDT)'],
            'oliveMill.destination.BIOMASS' => ['Biomassa / combustibile', 'Biomass / fuel', 'Biomasse / Brennstoff', 'Biomasse / combustible'],
            'oliveMill.destination.LAND_SPREADING' => ['Spandimento agronomico', 'Agronomic spreading', 'Agronomische Ausbringung', 'Épandage agronomique'],
            'oliveMill.destination.FEED' => ['Mangimistica', 'Animal feed', 'Futtermittel', 'Alimentation animale'],
            'oliveMill.destination.OTHER' => ['Altra destinazione', 'Other destination', 'Anderes Ziel', 'Autre destination'],
            'oliveMill.material.INOX_AISI_304' => ['Inox AISI 304', 'Stainless steel AISI 304', 'Edelstahl AISI 304', 'Inox AISI 304'],
            'oliveMill.material.INOX_AISI_316' => ['Inox AISI 316', 'Stainless steel AISI 316', 'Edelstahl AISI 316', 'Inox AISI 316'],
            'oliveMill.material.CONCRETE' => ['Cemento', 'Concrete', 'Beton', 'Béton'],
            'oliveMill.material.OTHER' => ['Altro materiale', 'Other material', 'Anderes Material', 'Autre matériau'],
            'oliveMill.warning.quality_below_declared' => ['La qualità analizzata è inferiore alla categoria dichiarata: l’olio è stoccato nella categoria inferiore.', 'The analysed quality is below the declared category: the oil is stored in the lower category.', 'Die analysierte Qualität liegt unter der deklarierten Kategorie: das Öl wird in der niedrigeren Kategorie gelagert.', 'La qualité analysée est inférieure à la catégorie déclarée : l’huile est stockée dans la catégorie inférieure.'],
            'oliveMill.warning.evo_acidity_exceeded' => ['L’acidità libera supera il limite dell’extra vergine (0,8 %).', 'The free acidity exceeds the extra virgin limit (0.8 %).', 'Die freie Säure überschreitet den Grenzwert für nativ extra (0,8 %).', 'L’acidité libre dépasse la limite de l’extra vierge (0,8 %).'],
        ];
    }

    /**
     * SIAN causal descriptions of the oil register.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function causals(): array
    {
        return [
            'oliveMill.causal.C01' => ['Carico da molitura conto proprio', 'Load from own-account milling', 'Zugang aus Eigenverarbeitung', 'Entrée issue du broyage pour compte propre'],
            'oliveMill.causal.C02' => ['Carico da molitura conto terzi', 'Load from third-party milling', 'Zugang aus Lohnverarbeitung', 'Entrée issue du travail à façon'],
            'oliveMill.causal.C03' => ['Acquisto / ritiro olio da molenda', 'Purchase / retention of oil from molenda', 'Kauf / Einbehalt von Öl aus Molenda', 'Achat / retenue d’huile issue de la molenda'],
            'oliveMill.causal.S01' => ['Scarico per vendita sfuso', 'Unload for bulk sale', 'Abgang für Losverkauf', 'Sortie pour vente en vrac'],
            'oliveMill.causal.S02' => ['Scarico per restituzione conto terzi', 'Unload for return to the third party', 'Abgang für Rückgabe an den Auftraggeber', 'Sortie pour restitution au tiers'],
            'oliveMill.causal.S03' => ['Scarico per confezionamento / imbottigliamento', 'Unload for packaging / bottling', 'Abgang für Verpackung / Abfüllung', 'Sortie pour conditionnement / embouteillage'],
            'oliveMill.causal.T01' => ['Travaso / taglio tra cisterne', 'Transfer / blend between tanks', 'Umfüllung / Verschnitt zwischen Tanks', 'Transfert / coupage entre cuves'],
            'oliveMill.causal.R01' => ['Rettifica / calo naturale', 'Rectification / natural loss', 'Berichtigung / natürlicher Schwund', 'Rectification / perte naturelle'],
        ];
    }

    /**
     * Codes the API reports with a refusal (oliveMill.error.<code>); the placeholders are the values of the problem context.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'oliveMill.error.unknown' => ['Operazione non riuscita. Riprovare.', 'The operation failed. Try again.', 'Der Vorgang ist fehlgeschlagen. Bitte erneut versuchen.', 'L’opération a échoué. Réessayez.'],
            'oliveMill.error.weight_invalid' => ['I pesi inseriti non sono validi: il lordo deve essere positivo e le tare non negative.', 'The weights are not valid: the gross weight must be positive and the tares not negative.', 'Die Gewichte sind ungültig: Brutto muss positiv und die Tara nicht negativ sein.', 'Les poids saisis ne sont pas valides : le brut doit être positif et les tares non négatives.'],
            'oliveMill.error.net_weight_not_positive' => ['Il peso netto risulta {net} kg: controllare lordo, tara e cassoni.', 'The net weight is {net} kg: check the gross weight, the tare and the crates.', 'Das Nettogewicht beträgt {net} kg: Brutto, Tara und Kisten prüfen.', 'Le poids net est de {net} kg : vérifier le brut, la tare et les caisses.'],
            'oliveMill.error.density_invalid' => ['La densità dell’olio deve essere positiva.', 'The oil density must be positive.', 'Die Öldichte muss positiv sein.', 'La densité de l’huile doit être positive.'],
            'oliveMill.error.input_weight_not_positive' => ['Il peso delle olive del ciclo deve essere positivo.', 'The weight of the crop of the cycle must be positive.', 'Das Erntegewicht des Zyklus muss positiv sein.', 'Le poids de la récolte du cycle doit être positif.'],
            'oliveMill.error.output_negative' => ['La quantità ottenuta non può essere negativa.', 'The quantity obtained cannot be negative.', 'Die gewonnene Menge darf nicht negativ sein.', 'La quantité obtenue ne peut pas être négative.'],
            'oliveMill.error.invalid_cuaa' => ['Il CUAA {cuaa} non è valido: indicare un codice fiscale o una partita IVA corretti.', 'The CUAA {cuaa} is not valid: enter a correct fiscal code or VAT number.', 'Die CUAA {cuaa} ist ungültig: einen korrekten Codice Fiscale oder eine korrekte MWST-Nummer angeben.', 'Le CUAA {cuaa} n’est pas valide : indiquer un code fiscal ou un numéro de TVA correct.'],
            'oliveMill.error.negative_value' => ['I valori non possono essere negativi.', 'The values cannot be negative.', 'Die Werte dürfen nicht negativ sein.', 'Les valeurs ne peuvent pas être négatives.'],
            'oliveMill.error.percentage_tariff_requires_in_kind' => ['La tariffa in percentuale pura richiede il pagamento in natura o misto.', 'The pure percentage tariff requires settlement in kind or hybrid.', 'Der reine Prozenttarif erfordert Naturalzahlung oder gemischte Abrechnung.', 'Le tarif en pourcentage pur exige un règlement en nature ou mixte.'],
            'oliveMill.error.percentage_out_of_range' => ['La percentuale deve essere compresa tra 0 e 100.', 'The percentage must be between 0 and 100.', 'Der Prozentsatz muss zwischen 0 und 100 liegen.', 'Le pourcentage doit être compris entre 0 et 100.'],
            'oliveMill.error.oil_price_required' => ['Indicare il prezzo di valorizzazione dell’olio trattenuto.', 'Enter the valuation price of the retained oil.', 'Den Bewertungspreis des einbehaltenen Öls angeben.', 'Indiquer le prix de valorisation de l’huile retenue.'],
            'oliveMill.error.retained_exceeds_available' => ['L’olio da trattenere ({retained} kg) supera quello lasciato in frantoio per la liquidazione ({available} kg).', 'The oil to retain ({retained} kg) exceeds the oil left at the mill for the settlement ({available} kg).', 'Das einzubehaltende Öl ({retained} kg) übersteigt das für die Abrechnung in der Mühle gelassene Öl ({available} kg).', 'L’huile à retenir ({retained} kg) dépasse celle laissée au moulin pour le règlement ({available} kg).'],
            'oliveMill.error.molenda_share_required' => ['Indicare la percentuale di molenda o i kg di olio da trattenere.', 'Enter the molenda percentage or the kg of oil to retain.', 'Den Molenda-Prozentsatz oder die einzubehaltenden kg Öl angeben.', 'Indiquer le pourcentage de molenda ou les kg d’huile à retenir.'],
            'oliveMill.error.dop_igp_mixing' => ['Un olio DOP o IGP non può essere miscelato con altre categorie ({tank} / {incoming}).', 'A DOP or IGP oil cannot be blended with other categories ({tank} / {incoming}).', 'Ein DOP- oder IGP-Öl darf nicht mit anderen Kategorien verschnitten werden ({tank} / {incoming}).', 'Une huile DOP ou IGP ne peut pas être coupée avec d’autres catégories ({tank} / {incoming}).'],
            'oliveMill.error.bio_segregation' => ['L’olio biologico va tenuto separato dagli altri oli ({tank} / {incoming}).', 'Organic oil must be kept apart from other oils ({tank} / {incoming}).', 'Bio-Öl muss von anderen Ölen getrennt gehalten werden ({tank} / {incoming}).', 'L’huile biologique doit rester séparée des autres huiles ({tank} / {incoming}).'],
            'oliveMill.error.origin_designation_mixing' => ['Un’origine DOP specifica non può essere miscelata con altre origini.', 'A specific DOP origin cannot be blended with other origins.', 'Eine spezifische DOP-Herkunft darf nicht mit anderen Herkünften verschnitten werden.', 'Une origine DOP spécifique ne peut pas être coupée avec d’autres origines.'],
            'oliveMill.error.tank_capacity_exceeded' => ['La capacità della cisterna ({capacity} kg) viene superata: giacenza {stock} kg, in arrivo {incoming} kg.', 'The tank capacity ({capacity} kg) would be exceeded: stock {stock} kg, incoming {incoming} kg.', 'Die Tankkapazität ({capacity} kg) würde überschritten: Bestand {stock} kg, Zugang {incoming} kg.', 'La capacité de la cuve ({capacity} kg) serait dépassée : stock {stock} kg, entrant {incoming} kg.'],
            'oliveMill.error.insufficient_tank_stock' => ['Giacenza insufficiente: disponibili {stock} kg, richiesti {requested} kg.', 'Not enough stock: {stock} kg available, {requested} kg requested.', 'Nicht genug Bestand: {stock} kg verfügbar, {requested} kg angefordert.', 'Stock insuffisant : {stock} kg disponibles, {requested} kg demandés.'],
            'oliveMill.error.counterparty_required' => ['Per questa causale serve un conferente o una controparte con CUAA valido (codice fiscale o partita IVA).', 'This causal needs a farmer or counterparty with a valid CUAA (fiscal code or VAT number).', 'Für diesen Grund wird ein Landwirt oder eine Gegenpartei mit gültiger CUAA (Codice Fiscale oder MWST-Nummer) benötigt.', 'Ce motif exige un agriculteur ou une contrepartie avec un CUAA valide (code fiscal ou numéro de TVA).'],
            'oliveMill.error.tank_not_found' => ['Cisterna non trovata.', 'Tank not found.', 'Tank nicht gefunden.', 'Cuve introuvable.'],
            'oliveMill.error.tank_inactive' => ['La cisterna {code} non è attiva.', 'Tank {code} is not active.', 'Tank {code} ist nicht aktiv.', 'La cuve {code} n’est pas active.'],
            'oliveMill.error.slot_conflict' => ['La linea {line} è già occupata in quell’orario dal lotto {lot}.', 'Line {line} is already booked at that time by lot {lot}.', 'Linie {line} ist zu dieser Zeit bereits durch Los {lot} belegt.', 'La ligne {line} est déjà occupée à cette heure par le lot {lot}.'],
            'oliveMill.error.phone_missing' => ['Manca il numero di cellulare del conferente per l’SMS.', 'The farmer’s mobile number for the SMS is missing.', 'Die Mobilnummer des Landwirts für die SMS fehlt.', 'Le numéro de mobile de l’agriculteur pour le SMS est manquant.'],
            'oliveMill.error.sms_failed' => ['Il gateway SMS non ha consegnato il messaggio.', 'The SMS gateway did not deliver the message.', 'Das SMS-Gateway hat die Nachricht nicht zugestellt.', 'La passerelle SMS n’a pas remis le message.'],
            'oliveMill.error.farmer_required' => ['Per la molitura conto terzi indicare il conferente.', 'Select the farmer for third-party milling.', 'Für die Lohnverarbeitung den Landwirt angeben.', 'Indiquer l’agriculteur pour le travail à façon.'],
            'oliveMill.error.farmer_not_found' => ['Conferente non trovato.', 'Farmer not found.', 'Landwirt nicht gefunden.', 'Agriculteur introuvable.'],
            'oliveMill.error.delivery_locked' => ['Il conferimento è in stato {status} e non è più modificabile.', 'The delivery is {status} and can no longer be changed.', 'Die Anlieferung hat den Status {status} und kann nicht mehr geändert werden.', 'La livraison est en statut {status} et ne peut plus être modifiée.'],
            'oliveMill.error.delivery_not_found' => ['Conferimento non trovato.', 'Delivery not found.', 'Anlieferung nicht gefunden.', 'Livraison introuvable.'],
            'oliveMill.error.delivery_not_weighable' => ['Il conferimento è in stato {status}: non si può più pesare.', 'The delivery is {status}: it can no longer be weighed.', 'Die Anlieferung hat den Status {status}: sie kann nicht mehr gewogen werden.', 'La livraison est en statut {status} : elle ne peut plus être pesée.'],
            'oliveMill.error.delivery_not_weighed' => ['Pesare il conferimento prima di metterlo in tramoggia.', 'Weigh the delivery before putting it in the hopper.', 'Die Anlieferung vor dem Einfüllen in den Trichter wiegen.', 'Peser la livraison avant de la mettre dans la trémie.'],
            'oliveMill.error.cycle_exists' => ['Il conferimento ha già un ciclo di frangitura.', 'The delivery already has a milling cycle.', 'Die Anlieferung hat bereits einen Pressvorgang.', 'La livraison a déjà un cycle de broyage.'],
            'oliveMill.error.cycle_not_found' => ['Ciclo di frangitura non trovato.', 'Milling cycle not found.', 'Pressvorgang nicht gefunden.', 'Cycle de broyage introuvable.'],
            'oliveMill.error.cycle_not_open' => ['Il ciclo è in stato {status}: non si può completare.', 'The cycle is {status}: it cannot be completed.', 'Der Zyklus hat den Status {status}: er kann nicht abgeschlossen werden.', 'Le cycle est en statut {status} : il ne peut pas être terminé.'],
            'oliveMill.error.output_required' => ['Indicare i kg o i litri ottenuti.', 'Enter the kg or litres obtained.', 'Die gewonnenen kg oder Liter angeben.', 'Indiquer les kg ou litres obtenus.'],
            'oliveMill.error.target_tank_required' => ['Scegliere la cisterna di destinazione dell’olio.', 'Choose the destination tank of the oil.', 'Den Zieltank des Öls wählen.', 'Choisir la cuve de destination de l’huile.'],
            'oliveMill.error.own_account_no_farmer_share' => ['Per conto proprio tutto l’olio va in cisterna: niente ritiro né molenda.', 'For own account all the oil goes to the tank: no pick-up and no molenda.', 'Bei Eigenverarbeitung geht das ganze Öl in den Tank: keine Abholung und keine Molenda.', 'Pour compte propre toute l’huile va en cuve : ni retrait ni molenda.'],
            'oliveMill.error.destination_mismatch' => ['Le destinazioni ({sum} kg) non coincidono con la quantità ottenuta ({output} kg).', 'The destinations ({sum} kg) do not match the quantity obtained ({output} kg).', 'Die Ziele ({sum} kg) stimmen nicht mit der gewonnenen Menge ({output} kg) überein.', 'Les destinations ({sum} kg) ne correspondent pas à la quantité obtenue ({output} kg).'],
            'oliveMill.error.cycle_not_completed' => ['Il ciclo è in stato {status}: completarlo prima della liquidazione.', 'The cycle is {status}: complete it before the settlement.', 'Der Zyklus hat den Status {status}: vor der Abrechnung abschließen.', 'Le cycle est en statut {status} : le terminer avant le règlement.'],
            'oliveMill.error.cycle_already_settled' => ['Il ciclo è già stato liquidato.', 'The cycle has already been settled.', 'Der Zyklus wurde bereits abgerechnet.', 'Le cycle a déjà été réglé.'],
            'oliveMill.error.own_account_no_settlement' => ['La molitura per conto proprio non ha liquidazione.', 'Own-account milling has no settlement.', 'Die Eigenverarbeitung hat keine Abrechnung.', 'Le broyage pour compte propre n’a pas de règlement.'],
            'oliveMill.error.service_amount_zero' => ['L’importo del servizio di molitura è zero: controllare la tariffa.', 'The milling service amount is zero: check the tariff.', 'Der Betrag der Lohnverarbeitung ist null: Tarif prüfen.', 'Le montant du service de broyage est nul : vérifier le tarif.'],
            'oliveMill.error.capacity_below_stock' => ['La capacità non può essere inferiore alla giacenza ({stock} kg).', 'The capacity cannot be below the stock ({stock} kg).', 'Die Kapazität darf nicht unter dem Bestand ({stock} kg) liegen.', 'La capacité ne peut pas être inférieure au stock ({stock} kg).'],
            'oliveMill.error.tank_in_use' => ['La cisterna ha giacenza ({stock} kg) o movimenti a registro: non si può disattivare né eliminare.', 'The tank holds stock ({stock} kg) or has register movements: it cannot be deactivated or deleted.', 'Der Tank hat Bestand ({stock} kg) oder Registerbewegungen: er kann nicht deaktiviert oder gelöscht werden.', 'La cuve a du stock ({stock} kg) ou des mouvements au registre : elle ne peut pas être désactivée ni supprimée.'],
            'oliveMill.error.same_tank' => ['La cisterna di origine e di destinazione devono essere diverse.', 'The source and destination tanks must be different.', 'Quell- und Zieltank müssen verschieden sein.', 'La cuve d’origine et de destination doivent être différentes.'],
            'oliveMill.error.tank_empty' => ['La cisterna {code} è vuota.', 'Tank {code} is empty.', 'Tank {code} ist leer.', 'La cuve {code} est vide.'],
            'oliveMill.error.quantity_must_be_positive' => ['La quantità deve essere maggiore di zero.', 'The quantity must be greater than zero.', 'Die Menge muss größer als null sein.', 'La quantité doit être supérieure à zéro.'],
            'oliveMill.error.nothing_to_transmit' => ['Non ci sono operazioni da trasmettere.', 'There are no operations to transmit.', 'Es gibt keine zu übermittelnden Vorgänge.', 'Il n’y a aucune opération à transmettre.'],
            'oliveMill.error.counterparty_not_found' => ['Controparte non trovata.', 'Counterparty not found.', 'Gegenpartei nicht gefunden.', 'Contrepartie introuvable.'],
            'oliveMill.error.transport_document_required' => ['La sansa richiede il documento di trasporto (DDT).', 'Pomace requires the transport document (DDT).', 'Der Olivenkuchen erfordert das Transportdokument (DDT).', 'Les grignons exigent le document de transport (DDT).'],
            'oliveMill.error.spreading_parcel_required' => ['Per lo spandimento indicare la particella catastale e la superficie in ettari.', 'For the spreading enter the cadastral parcel and its area in hectares.', 'Für die Ausbringung die Katasterparzelle und die Fläche in Hektar angeben.', 'Pour l’épandage indiquer la parcelle cadastrale et sa surface en hectares.'],
            'oliveMill.error.spreading_limit_exceeded' => ['Limite di spandimento superato: {per_hectare} m³/ha nell’anno, massimo {limit} m³/ha.', 'Spreading limit exceeded: {per_hectare} m³/ha in the year, maximum {limit} m³/ha.', 'Ausbringungsgrenze überschritten: {per_hectare} m³/ha im Jahr, Höchstwert {limit} m³/ha.', 'Limite d’épandage dépassée : {per_hectare} m³/ha dans l’année, maximum {limit} m³/ha.'],
            'oliveMill.error.malformed_batch' => ['Il file SIAN non è ben formato.', 'The SIAN file is not well formed.', 'Die SIAN-Datei ist nicht wohlgeformt.', 'Le fichier SIAN n’est pas bien formé.'],
            'oliveMill.error.sian_endpoint_missing' => ['Il servizio web SIAN non è configurato.', 'The SIAN web service is not configured.', 'Der SIAN-Webservice ist nicht konfiguriert.', 'Le service web SIAN n’est pas configuré.'],
            'oliveMill.error.sian_unreachable' => ['Il servizio SIAN non è raggiungibile.', 'The SIAN service is unreachable.', 'Der SIAN-Dienst ist nicht erreichbar.', 'Le service SIAN est injoignable.'],
            'oliveMill.error.sian_refused' => ['Il servizio SIAN ha rifiutato il file.', 'The SIAN service refused the file.', 'Der SIAN-Dienst hat die Datei abgelehnt.', 'Le service SIAN a refusé le fichier.'],
            'oliveMill.error.sian_protocol_missing' => ['Il SIAN non ha restituito il numero di protocollo.', 'The SIAN did not return the protocol number.', 'Der SIAN hat keine Protokollnummer zurückgegeben.', 'Le SIAN n’a pas renvoyé le numéro de protocole.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'oliveMill.field.processingMode' => ['Modalità di lavorazione', 'Processing mode', 'Verarbeitungsart', 'Mode de traitement'],
            'oliveMill.field.commodity' => ['Prodotto', 'Crop', 'Erntegut', 'Produit'],
            'oliveMill.field.farmer' => ['Conferente', 'Farmer', 'Landwirt', 'Agriculteur'],
            'oliveMill.field.farmerName' => ['Nome o ragione sociale', 'Name or company name', 'Name oder Firmenname', 'Nom ou raison sociale'],
            'oliveMill.field.phone' => ['Cellulare', 'Mobile phone', 'Mobiltelefon', 'Téléphone mobile'],
            'oliveMill.field.fiscalCode' => ['Codice fiscale (CUAA)', 'Fiscal code (CUAA)', 'Codice Fiscale (CUAA)', 'Code fiscal (CUAA)'],
            'oliveMill.field.deliveredAt' => ['Data e ora del conferimento', 'Delivery date and time', 'Datum und Uhrzeit der Anlieferung', 'Date et heure de la livraison'],
            'oliveMill.field.certification' => ['Categoria / certificazione', 'Category / certification', 'Kategorie / Zertifizierung', 'Catégorie / certification'],
            'oliveMill.field.cultivar' => ['Cultivar', 'Cultivar', 'Sorte', 'Cultivar'],
            'oliveMill.field.grainType' => ['Tipo di cereale (grano, duro, farro…)', 'Grain type (wheat, durum, spelt…)', 'Getreideart (Weizen, Hartweizen, Dinkel…)', 'Type de céréale (blé, dur, épeautre…)'],
            'oliveMill.field.originMunicipality' => ['Comune di origine', 'Municipality of origin', 'Herkunftsgemeinde', 'Commune d’origine'],
            'oliveMill.field.cadastralReference' => ['Riferimento catastale', 'Cadastral reference', 'Katasterangabe', 'Référence cadastrale'],
            'oliveMill.field.sianOriginCode' => ['Codice origine SIAN', 'SIAN origin code', 'SIAN-Herkunftscode', 'Code d’origine SIAN'],
            'oliveMill.field.scheduledAt' => ['Inizio slot di frangitura', 'Milling slot start', 'Beginn des Presstermins', 'Début du créneau de broyage'],
            'oliveMill.field.estimatedDuration' => ['Durata stimata (minuti)', 'Estimated duration (minutes)', 'Geschätzte Dauer (Minuten)', 'Durée estimée (minutes)'],
            'oliveMill.field.hopperLine' => ['Linea / tramoggia', 'Line / hopper', 'Linie / Trichter', 'Ligne / trémie'],
            'oliveMill.field.smsPhone' => ['Cellulare per il promemoria SMS', 'Mobile number for the SMS reminder', 'Mobilnummer für die SMS-Erinnerung', 'Mobile pour le rappel SMS'],
            'oliveMill.field.weighNow' => ['Registra subito la pesata', 'Record the weighing now', 'Wägung jetzt erfassen', 'Enregistrer la pesée maintenant'],
            'oliveMill.field.grossWeight' => ['Peso lordo (kg)', 'Gross weight (kg)', 'Bruttogewicht (kg)', 'Poids brut (kg)'],
            'oliveMill.field.vehicleTare' => ['Tara del mezzo (kg)', 'Vehicle tare (kg)', 'Fahrzeugtara (kg)', 'Tare du véhicule (kg)'],
            'oliveMill.field.crateCount' => ['Numero di cassoni', 'Number of crates', 'Anzahl Kisten', 'Nombre de caisses'],
            'oliveMill.field.crateUnitTare' => ['Tara di un cassone (kg)', 'Tare of one crate (kg)', 'Tara einer Kiste (kg)', 'Tare d’une caisse (kg)'],
            'oliveMill.field.ticket' => ['Numero bolletta di pesa', 'Weighbridge ticket number', 'Wägescheinnummer', 'Numéro du ticket de pesée'],
            'oliveMill.field.totalTare' => ['Tara totale', 'Total tare', 'Gesamttara', 'Tare totale'],
            'oliveMill.field.netWeight' => ['Peso netto', 'Net weight', 'Nettogewicht', 'Poids net'],
            'oliveMill.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'oliveMill.field.malaxationTemperature' => ['Temperatura di gramolatura (°C)', 'Malaxation temperature (°C)', 'Malaxiertemperatur (°C)', 'Température de malaxage (°C)'],
            'oliveMill.field.waterAdded' => ['Acqua aggiunta (litri)', 'Water added (litres)', 'Zugegebenes Wasser (Liter)', 'Eau ajoutée (litres)'],
            'oliveMill.field.outputKg' => ['Olio / farina ottenuti (kg)', 'Oil / flour obtained (kg)', 'Gewonnenes Öl / Mehl (kg)', 'Huile / farine obtenues (kg)'],
            'oliveMill.field.outputLiters' => ['Olio ottenuto (litri)', 'Oil obtained (litres)', 'Gewonnenes Öl (Liter)', 'Huile obtenue (litres)'],
            'oliveMill.field.acidity' => ['Acidità libera (%)', 'Free acidity (%)', 'Freie Säure (%)', 'Acidité libre (%)'],
            'oliveMill.field.peroxides' => ['Numero di perossidi', 'Peroxide value', 'Peroxidzahl', 'Indice de peroxydes'],
            'oliveMill.field.polyphenols' => ['Polifenoli (mg/kg)', 'Polyphenols (mg/kg)', 'Polyphenole (mg/kg)', 'Polyphénols (mg/kg)'],
            'oliveMill.field.originDesignation' => ['Designazione di origine', 'Origin designation', 'Herkunftsbezeichnung', 'Désignation d’origine'],
            'oliveMill.field.panelNotes' => ['Note del panel test', 'Panel test notes', 'Notizen des Panel-Tests', 'Notes du panel test'],
            'oliveMill.field.pickup' => ['Ritiro diretto del cliente (kg)', 'Direct pick-up by the customer (kg)', 'Direkte Abholung durch den Kunden (kg)', 'Retrait direct par le client (kg)'],
            'oliveMill.field.molenda' => ['Lasciato per la liquidazione / molenda (kg)', 'Left for settlement / molenda (kg)', 'Für Abrechnung / Molenda belassen (kg)', 'Laissé pour le règlement / molenda (kg)'],
            'oliveMill.field.millStock' => ['Stoccato in frantoio (kg)', 'Stored at the mill (kg)', 'In der Mühle gelagert (kg)', 'Stocké au moulin (kg)'],
            'oliveMill.field.targetTank' => ['Cisterna di destinazione', 'Destination tank', 'Zieltank', 'Cuve de destination'],
            'oliveMill.field.destinationTank' => ['Cisterna di destinazione', 'Destination tank', 'Zieltank', 'Cuve de destination'],
            'oliveMill.field.tariffMode' => ['Tariffa di molitura', 'Milling tariff', 'Lohnverarbeitungstarif', 'Tarif de broyage'],
            'oliveMill.field.tariffValue.PER_QUINTAL_OLIVES' => ['€ per quintale di olive', '€ per quintal of olives', '€ pro Doppelzentner Oliven', '€ par quintal d’olives'],
            'oliveMill.field.tariffValue.PER_KG_OIL' => ['€ per kg di olio', '€ per kg of oil', '€ pro kg Öl', '€ par kg d’huile'],
            'oliveMill.field.tariffValue.FLAT_FEE' => ['Importo forfettario (€)', 'Flat amount (€)', 'Pauschalbetrag (€)', 'Montant forfaitaire (€)'],
            'oliveMill.field.tariffValue.PURE_PERCENTAGE_MOLENDA' => ['Molenda (% dell’olio)', 'Molenda (% of the oil)', 'Molenda (% des Öls)', 'Molenda (% de l’huile)'],
            'oliveMill.field.serviceVatRate' => ['Aliquota IVA del servizio (%)', 'VAT rate of the service (%)', 'MWST-Satz der Dienstleistung (%)', 'Taux de TVA du service (%)'],
            'oliveMill.field.settlementMode' => ['Modalità di liquidazione', 'Settlement mode', 'Abrechnungsart', 'Mode de règlement'],
            'oliveMill.field.molendaPercent' => ['Molenda (% dell’olio trattenuto)', 'Molenda (% of the oil retained)', 'Molenda (% des einbehaltenen Öls)', 'Molenda (% de l’huile retenue)'],
            'oliveMill.field.fixedRetainedKg' => ['Oppure kg di olio fissi da trattenere', 'Or fixed kg of oil to retain', 'Oder feste kg Öl zum Einbehalt', 'Ou kg d’huile fixes à retenir'],
            'oliveMill.field.oilPrice' => ['Prezzo di valorizzazione dell’olio (€/kg)', 'Oil valuation price (€/kg)', 'Bewertungspreis des Öls (€/kg)', 'Prix de valorisation de l’huile (€/kg)'],
            'oliveMill.field.purchaseVatRate' => ['Aliquota IVA dell’acquisto dell’olio (%)', 'VAT rate of the oil purchase (%)', 'MWST-Satz des Ölkaufs (%)', 'Taux de TVA de l’achat d’huile (%)'],
            'oliveMill.field.quantityKg' => ['Quantità (kg)', 'Quantity (kg)', 'Menge (kg)', 'Quantité (kg)'],
            'oliveMill.field.operationDate' => ['Data operazione', 'Operation date', 'Vorgangsdatum', 'Date de l’opération'],
            'oliveMill.field.bottleFormat' => ['Formato', 'Format', 'Format', 'Format'],
            'oliveMill.field.bottleCount' => ['Numero di bottiglie', 'Number of bottles', 'Anzahl Flaschen', 'Nombre de bouteilles'],
            'oliveMill.field.packageLot' => ['Lotto di confezionamento', 'Packaging lot', 'Abfülllos', 'Lot de conditionnement'],
            'oliveMill.field.reason' => ['Motivo della rettifica', 'Reason for the rectification', 'Grund der Berichtigung', 'Motif de la rectification'],
            'oliveMill.field.tankCode' => ['Codice cisterna SIAN', 'SIAN tank code', 'SIAN-Tankcode', 'Code de cuve SIAN'],
            'oliveMill.field.material' => ['Materiale', 'Material', 'Material', 'Matériau'],
            'oliveMill.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'oliveMill.field.capacityKg' => ['Capacità (kg)', 'Capacity (kg)', 'Kapazität (kg)', 'Capacité (kg)'],
            'oliveMill.field.temperature' => ['Temperatura (°C)', 'Temperature (°C)', 'Temperatur (°C)', 'Température (°C)'],
            'oliveMill.field.gridRow' => ['Riga sulla mappa', 'Map row', 'Zeile auf der Karte', 'Ligne sur la carte'],
            'oliveMill.field.gridCol' => ['Colonna sulla mappa', 'Map column', 'Spalte auf der Karte', 'Colonne sur la carte'],
            'oliveMill.field.zoneLabel' => ['Zona', 'Zone', 'Zone', 'Zone'],
            'oliveMill.field.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'oliveMill.field.byproductType' => ['Sottoprodotto', 'By-product', 'Nebenprodukt', 'Sous-produit'],
            'oliveMill.field.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'oliveMill.field.destinationType' => ['Destinazione', 'Destination', 'Ziel', 'Destination'],
            'oliveMill.field.counterpartyName' => ['Controparte (sansificio, acquirente)', 'Counterparty (pomace mill, buyer)', 'Gegenpartei (Nachpresserei, Käufer)', 'Contrepartie (usine de grignons, acheteur)'],
            'oliveMill.field.transportDocument' => ['Documento di trasporto (DDT)', 'Transport document (DDT)', 'Transportdokument (DDT)', 'Document de transport (DDT)'],
            'oliveMill.field.cadastralParcel' => ['Particella catastale', 'Cadastral parcel', 'Katasterparzelle', 'Parcelle cadastrale'],
            'oliveMill.field.parcelArea' => ['Superficie della particella (ha)', 'Parcel area (ha)', 'Fläche der Parzelle (ha)', 'Surface de la parcelle (ha)'],
            'oliveMill.filter.campaign' => ['Campagna', 'Campaign', 'Kampagne', 'Campagne'],
            'oliveMill.filter.search' => ['Cerca lotto, cultivar o bolletta', 'Search lot, cultivar or ticket', 'Los, Sorte oder Wägeschein suchen', 'Chercher lot, cultivar ou ticket'],
            'oliveMill.filter.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'oliveMill.filter.commodity' => ['Prodotto', 'Crop', 'Erntegut', 'Produit'],
            'oliveMill.filter.from' => ['Dal', 'From', 'Ab', 'Du'],
            'oliveMill.filter.days' => ['Giorni', 'Days', 'Tage', 'Jours'],
            'oliveMill.filter.line' => ['Linea', 'Line', 'Linie', 'Ligne'],
            'oliveMill.filter.causal' => ['Causale', 'Causal', 'Grund', 'Motif'],
            'oliveMill.filter.pendingOnly' => ['Solo da trasmettere', 'Only to be transmitted', 'Nur zu übermitteln', 'Seulement à transmettre'],
            'oliveMill.filter.byproductType' => ['Sottoprodotto', 'By-product', 'Nebenprodukt', 'Sous-produit'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function columns(): array
    {
        return [
            'oliveMill.column.lot' => ['Lotto', 'Lot', 'Los', 'Lot'],
            'oliveMill.column.deliveredAt' => ['Conferito il', 'Delivered on', 'Angeliefert am', 'Livré le'],
            'oliveMill.column.farmer' => ['Conferente', 'Farmer', 'Landwirt', 'Agriculteur'],
            'oliveMill.column.commodity' => ['Prodotto', 'Crop', 'Erntegut', 'Produit'],
            'oliveMill.column.certification' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'oliveMill.column.netWeight' => ['Peso netto (kg)', 'Net weight (kg)', 'Nettogewicht (kg)', 'Poids net (kg)'],
            'oliveMill.column.slot' => ['Slot', 'Slot', 'Termin', 'Créneau'],
            'oliveMill.column.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'oliveMill.column.sms' => ['SMS', 'SMS', 'SMS', 'SMS'],
            'oliveMill.column.line' => ['Linea', 'Line', 'Linie', 'Ligne'],
            'oliveMill.column.startedAt' => ['Avvio', 'Started', 'Beginn', 'Démarrage'],
            'oliveMill.column.output' => ['Ottenuto (kg)', 'Obtained (kg)', 'Gewonnen (kg)', 'Obtenu (kg)'],
            'oliveMill.column.yield' => ['Resa', 'Yield', 'Ausbeute', 'Rendement'],
            'oliveMill.column.molenda' => ['Molenda (kg)', 'Molenda (kg)', 'Molenda (kg)', 'Molenda (kg)'],
            'oliveMill.column.settledAt' => ['Liquidato il', 'Settled on', 'Abgerechnet am', 'Réglé le'],
            'oliveMill.column.settlementMode' => ['Modalità', 'Mode', 'Art', 'Mode'],
            'oliveMill.column.serviceTotal' => ['Fattura servizio', 'Service invoice', 'Dienstleistungsrechnung', 'Facture du service'],
            'oliveMill.column.retainedKg' => ['Olio trattenuto (kg)', 'Oil retained (kg)', 'Einbehaltenes Öl (kg)', 'Huile retenue (kg)'],
            'oliveMill.column.purchaseTotal' => ['Acquisto olio', 'Oil purchase', 'Ölkauf', 'Achat d’huile'],
            'oliveMill.column.cashBalance' => ['Saldo in denaro', 'Cash balance', 'Barsaldo', 'Solde en espèces'],
            'oliveMill.column.operationDate' => ['Data', 'Date', 'Datum', 'Date'],
            'oliveMill.column.causal' => ['Causale', 'Causal', 'Grund', 'Motif'],
            'oliveMill.column.tanks' => ['Cisterne', 'Tanks', 'Tanks', 'Cuves'],
            'oliveMill.column.counterparty' => ['Controparte', 'Counterparty', 'Gegenpartei', 'Contrepartie'],
            'oliveMill.column.quantityKg' => ['Quantità (kg)', 'Quantity (kg)', 'Menge (kg)', 'Quantité (kg)'],
            'oliveMill.column.category' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'oliveMill.column.deadline' => ['Scadenza invio SIAN', 'SIAN deadline', 'SIAN-Frist', 'Échéance d’envoi SIAN'],
            'oliveMill.column.byproduct' => ['Sottoprodotto', 'By-product', 'Nebenprodukt', 'Sous-produit'],
            'oliveMill.column.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'oliveMill.column.destination' => ['Destinazione', 'Destination', 'Ziel', 'Destination'],
            'oliveMill.column.transportDocument' => ['DDT', 'DDT', 'DDT', 'DDT'],
            'oliveMill.column.parcel' => ['Particella', 'Parcel', 'Parzelle', 'Parcelle'],
            'oliveMill.column.perHectare' => ['m³/ha', 'm³/ha', 'm³/ha', 'm³/ha'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'oliveMill.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'oliveMill.action.close' => ['Chiudi', 'Close', 'Schließen', 'Fermer'],
            'oliveMill.action.cancel' => ['Annulla lotto', 'Cancel lot', 'Los stornieren', 'Annuler le lot'],
            'oliveMill.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'oliveMill.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'oliveMill.action.confirmCancel' => ['Annullare questo conferimento?', 'Cancel this delivery?', 'Diese Anlieferung stornieren?', 'Annuler cette livraison ?'],
            'oliveMill.action.confirmDelete' => ['Eliminare questa cisterna?', 'Delete this tank?', 'Diesen Tank löschen?', 'Supprimer cette cuve ?'],
            'oliveMill.action.newDelivery' => ['Nuovo conferimento', 'New delivery', 'Neue Anlieferung', 'Nouvelle livraison'],
            'oliveMill.action.quickCreateFarmer' => ['Nuovo conferente', 'New farmer', 'Neuer Landwirt', 'Nouvel agriculteur'],
            'oliveMill.action.sendSms' => ['Invia SMS tramoggia', 'Send hopper SMS', 'Trichter-SMS senden', 'Envoyer le SMS trémie'],
            'oliveMill.action.startCycle' => ['Avvia frangitura', 'Start milling', 'Pressung starten', 'Démarrer le broyage'],
            'oliveMill.action.completeCycle' => ['Completa ciclo', 'Complete cycle', 'Zyklus abschließen', 'Terminer le cycle'],
            'oliveMill.action.settle' => ['Liquida', 'Settle', 'Abrechnen', 'Régler'],
            'oliveMill.action.settleNow' => ['Liquida ed emetti i documenti', 'Settle and issue the documents', 'Abrechnen und Belege erstellen', 'Régler et émettre les documents'],
            'oliveMill.action.newTank' => ['Nuova cisterna', 'New tank', 'Neuer Tank', 'Nouvelle cuve'],
            'oliveMill.action.transfer' => ['Travaso / taglio', 'Transfer / blend', 'Umfüllen / Verschneiden', 'Transfert / coupage'],
            'oliveMill.action.package' => ['Confezionamento', 'Bottling', 'Abfüllung', 'Conditionnement'],
            'oliveMill.action.adjust' => ['Rettifica / calo', 'Rectify / loss', 'Berichtigung / Schwund', 'Rectification / perte'],
            'oliveMill.action.exportXml' => ['Scarica XML SIAN', 'Download SIAN XML', 'SIAN-XML herunterladen', 'Télécharger le XML SIAN'],
            'oliveMill.action.exportCsv' => ['Scarica CSV', 'Download CSV', 'CSV herunterladen', 'Télécharger le CSV'],
            'oliveMill.action.transmitAll' => ['Trasmetti a SIAN (tutte le operazioni da inviare)', 'Transmit to SIAN (all pending operations)', 'An SIAN übermitteln (alle offenen Vorgänge)', 'Transmettre au SIAN (toutes les opérations à envoyer)'],
            'oliveMill.action.transmitSelected' => ['Trasmetti a SIAN ({count} selezionate)', 'Transmit to SIAN ({count} selected)', 'An SIAN übermitteln ({count} ausgewählt)', 'Transmettre au SIAN ({count} sélectionnées)'],
            'oliveMill.action.receipt' => ['Ricevuta', 'Receipt', 'Quittung', 'Accusé'],
            'oliveMill.action.newByproduct' => ['Nuovo movimento sottoprodotto', 'New by-product movement', 'Neue Nebenproduktbewegung', 'Nouveau mouvement de sous-produit'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'oliveMill.section.dashboardSubtitle' => ['Quintali moliti, resa media, olio trattenuto in molenda e scadenze SIAN della campagna.', 'Quintals milled, average yield, oil kept as molenda and the SIAN deadlines of the campaign.', 'Verarbeitete Doppelzentner, durchschnittliche Ausbeute, als Molenda einbehaltenes Öl und SIAN-Fristen der Kampagne.', 'Quintaux broyés, rendement moyen, huile retenue en molenda et échéances SIAN de la campagne.'],
            'oliveMill.section.deliveriesSubtitle' => ['Conferimenti di olive e cereali con pesa, tara automatica e prenotazione della tramoggia.', 'Olive and grain deliveries with weighbridge, automatic tare and hopper booking.', 'Anlieferungen von Oliven und Getreide mit Waage, automatischer Tara und Trichterbuchung.', 'Livraisons d’olives et de céréales avec pesée, tare automatique et réservation de la trémie.'],
            'oliveMill.section.scheduleSubtitle' => ['Slot per linea di frangitura, cicli in corso e chiusura con resa e destinazione del prodotto.', 'Slots per milling line, cycles in progress and completion with yield and product routing.', 'Termine pro Pressline, laufende Zyklen und Abschluss mit Ausbeute und Produktverteilung.', 'Créneaux par ligne de broyage, cycles en cours et clôture avec rendement et destination du produit.'],
            'oliveMill.section.settlementsSubtitle' => ['Fattura del servizio, acquisto dell’olio trattenuto e saldo in denaro in un’unica liquidazione.', 'Service invoice, purchase of the retained oil and cash balance in a single settlement.', 'Dienstleistungsrechnung, Kauf des einbehaltenen Öls und Barsaldo in einer einzigen Abrechnung.', 'Facture du service, achat de l’huile retenue et solde en espèces en un seul règlement.'],
            'oliveMill.section.tanksSubtitle' => ['Livello, categoria, origine e lotti di ogni cisterna, con travasi, confezionamento e rettifiche.', 'Level, category, origin and lots of every tank, with transfers, bottling and rectifications.', 'Füllstand, Kategorie, Herkunft und Lose jedes Tanks, mit Umfüllungen, Abfüllung und Berichtigungen.', 'Niveau, catégorie, origine et lots de chaque cuve, avec transferts, conditionnement et rectifications.'],
            'oliveMill.section.sianSubtitle' => ['Registro telematico dell’olio con il conto alla rovescia dei 6 giorni, la trasmissione e il registro dei sottoprodotti.', 'Telematic oil register with the 6-day countdown, the transmission and the by-product register.', 'Telematisches Ölregister mit 6-Tage-Countdown, Übermittlung und Nebenproduktregister.', 'Registre télématique de l’huile avec le compte à rebours de 6 jours, la transmission et le registre des sous-produits.'],
            'oliveMill.section.yieldTrend' => ['Andamento della resa', 'Yield trend', 'Ausbeuteverlauf', 'Évolution du rendement'],
            'oliveMill.section.hoppers' => ['Tramogge attive', 'Active hoppers', 'Aktive Trichter', 'Trémies actives'],
            'oliveMill.section.stockByCategory' => ['Giacenza per categoria di olio', 'Stock by oil category', 'Bestand nach Ölkategorie', 'Stock par catégorie d’huile'],
            'oliveMill.section.deliveriesByStatus' => ['Conferimenti per stato', 'Deliveries by status', 'Anlieferungen nach Status', 'Livraisons par statut'],
            'oliveMill.section.byproducts' => ['Sottoprodotti', 'By-products', 'Nebenprodukte', 'Sous-produits'],
            'oliveMill.section.delivery' => ['Conferimento', 'Delivery', 'Anlieferung', 'Livraison'],
            'oliveMill.section.booking' => ['Prenotazione tramoggia', 'Hopper booking', 'Trichterbuchung', 'Réservation de la trémie'],
            'oliveMill.section.weighbridge' => ['Pesa', 'Weighbridge', 'Waage', 'Pesée'],
            'oliveMill.section.cyclesInProgress' => ['Cicli in corso', 'Cycles in progress', 'Laufende Zyklen', 'Cycles en cours'],
            'oliveMill.section.slotBoard' => ['Calendario degli slot', 'Slot calendar', 'Terminkalender', 'Calendrier des créneaux'],
            'oliveMill.section.startCycle' => ['Avvio del ciclo di frangitura', 'Start of the milling cycle', 'Start des Pressvorgangs', 'Démarrage du cycle de broyage'],
            'oliveMill.section.completeCycle' => ['Chiusura del ciclo di frangitura', 'Completion of the milling cycle', 'Abschluss des Pressvorgangs', 'Clôture du cycle de broyage'],
            'oliveMill.section.destinations' => ['Destinazione del prodotto', 'Product routing', 'Produktverteilung', 'Destination du produit'],
            'oliveMill.section.toSettle' => ['Cicli da liquidare', 'Cycles to settle', 'Abzurechnende Zyklen', 'Cycles à régler'],
            'oliveMill.section.settled' => ['Liquidazioni eseguite', 'Settlements done', 'Durchgeführte Abrechnungen', 'Règlements effectués'],
            'oliveMill.section.settlementWizard' => ['Liquidazione e molenda', 'Settlement and molenda', 'Abrechnung und Molenda', 'Règlement et molenda'],
            'oliveMill.section.tank.transfer' => ['Travaso / taglio tra cisterne', 'Transfer / blend between tanks', 'Umfüllung / Verschnitt zwischen Tanks', 'Transfert / coupage entre cuves'],
            'oliveMill.section.tank.package' => ['Confezionamento ed etichettatura', 'Bottling and labelling', 'Abfüllung und Etikettierung', 'Conditionnement et étiquetage'],
            'oliveMill.section.tank.adjust' => ['Rettifica della giacenza (R01)', 'Stock rectification (R01)', 'Bestandsberichtigung (R01)', 'Rectification du stock (R01)'],
            'oliveMill.section.tank.tank' => ['Cisterna', 'Tank', 'Tank', 'Cuve'],
            'oliveMill.section.receipt' => ['Ricevuta SIAN dell’operazione', 'SIAN receipt of the operation', 'SIAN-Quittung des Vorgangs', 'Accusé SIAN de l’opération'],
            'oliveMill.section.byproductMovement' => ['Movimento di sottoprodotto', 'By-product movement', 'Nebenproduktbewegung', 'Mouvement de sous-produit'],
            'oliveMill.tab.register' => ['Registro SIAN', 'SIAN register', 'SIAN-Register', 'Registre SIAN'],
            'oliveMill.tab.byproducts' => ['Registro sottoprodotti', 'By-product register', 'Nebenproduktregister', 'Registre des sous-produits'],
            'oliveMill.empty.deliveries' => ['Nessun conferimento.', 'No deliveries.', 'Keine Anlieferungen.', 'Aucune livraison.'],
            'oliveMill.empty.hoppers' => ['Nessuna tramoggia in lavorazione.', 'No hopper in operation.', 'Kein Trichter in Betrieb.', 'Aucune trémie en fonctionnement.'],
            'oliveMill.empty.bookings' => ['Nessuna prenotazione nel periodo.', 'No bookings in the period.', 'Keine Buchungen im Zeitraum.', 'Aucune réservation sur la période.'],
            'oliveMill.empty.toSettle' => ['Nessun ciclo da liquidare.', 'No cycles to settle.', 'Keine abzurechnenden Zyklen.', 'Aucun cycle à régler.'],
            'oliveMill.empty.settlements' => ['Nessuna liquidazione.', 'No settlements.', 'Keine Abrechnungen.', 'Aucun règlement.'],
            'oliveMill.empty.tanks' => ['Nessuna cisterna registrata.', 'No tanks registered.', 'Keine Tanks erfasst.', 'Aucune cuve enregistrée.'],
            'oliveMill.empty.movements' => ['Nessuna operazione a registro.', 'No register operations.', 'Keine Registervorgänge.', 'Aucune opération au registre.'],
            'oliveMill.empty.byproducts' => ['Nessun movimento di sottoprodotti.', 'No by-product movements.', 'Keine Nebenproduktbewegungen.', 'Aucun mouvement de sous-produits.'],
            'oliveMill.empty.trend' => ['Nessun ciclo completato nella campagna.', 'No completed cycle in the campaign.', 'Kein abgeschlossener Zyklus in der Kampagne.', 'Aucun cycle terminé dans la campagne.'],
            'oliveMill.tag.empty' => ['Vuota', 'Empty', 'Leer', 'Vide'],
            'oliveMill.tag.emptyTanks' => ['Cisterne vuote', 'Empty tanks', 'Leere Tanks', 'Cuves vides'],
            'oliveMill.tag.inactive' => ['Non attiva', 'Inactive', 'Inaktiv', 'Inactive'],
            'oliveMill.kpi.quintalsMilled' => ['Quintali moliti', 'Quintals milled', 'Verarbeitete Doppelzentner', 'Quintaux broyés'],
            'oliveMill.kpi.averageYield' => ['Resa media', 'Average yield', 'Durchschnittliche Ausbeute', 'Rendement moyen'],
            'oliveMill.kpi.oilProduced' => ['Olio prodotto', 'Oil produced', 'Erzeugtes Öl', 'Huile produite'],
            'oliveMill.kpi.molendaRetained' => ['Olio trattenuto (molenda)', 'Oil retained (molenda)', 'Einbehaltenes Öl (Molenda)', 'Huile retenue (molenda)'],
            'oliveMill.kpi.grainMilled' => ['Cereali molti', 'Grain milled', 'Verarbeitetes Getreide', 'Céréales moulues'],
            'oliveMill.kpi.coldExtraction' => ['Cicli a freddo', 'Cold extraction cycles', 'Kaltextraktionszyklen', 'Cycles à froid'],
            'oliveMill.kpi.sianPending' => ['Operazioni SIAN da inviare', 'SIAN operations to send', 'Zu sendende SIAN-Vorgänge', 'Opérations SIAN à envoyer'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'oliveMill.message.deliverySaved' => ['Conferimento salvato.', 'The delivery was saved.', 'Die Anlieferung wurde gespeichert.', 'La livraison a été enregistrée.'],
            'oliveMill.message.deliveryCancelled' => ['Conferimento annullato.', 'The delivery was cancelled.', 'Die Anlieferung wurde storniert.', 'La livraison a été annulée.'],
            'oliveMill.message.smsSent' => ['SMS inviato al conferente.', 'The SMS was sent to the farmer.', 'Die SMS wurde an den Landwirt gesendet.', 'Le SMS a été envoyé à l’agriculteur.'],
            'oliveMill.message.cycleStarted' => ['Ciclo di frangitura avviato.', 'The milling cycle started.', 'Der Pressvorgang wurde gestartet.', 'Le cycle de broyage a démarré.'],
            'oliveMill.message.cycleCompleted' => ['Ciclo completato: resa calcolata e prodotto ripartito.', 'Cycle completed: yield calculated and product routed.', 'Zyklus abgeschlossen: Ausbeute berechnet und Produkt verteilt.', 'Cycle terminé : rendement calculé et produit réparti.'],
            'oliveMill.message.settled' => ['Liquidazione eseguita: fattura, acquisto dell’olio e carico SIAN registrati.', 'Settlement done: invoice, oil purchase and SIAN load recorded.', 'Abrechnung durchgeführt: Rechnung, Ölkauf und SIAN-Zugang erfasst.', 'Règlement effectué : facture, achat d’huile et entrée SIAN enregistrés.'],
            'oliveMill.message.tankSaved' => ['Cisterna salvata.', 'The tank was saved.', 'Der Tank wurde gespeichert.', 'La cuve a été enregistrée.'],
            'oliveMill.message.tankDeleted' => ['Cisterna eliminata.', 'The tank was deleted.', 'Der Tank wurde gelöscht.', 'La cuve a été supprimée.'],
            'oliveMill.message.transferDone' => ['Travaso registrato.', 'The transfer was recorded.', 'Die Umfüllung wurde erfasst.', 'Le transfert a été enregistré.'],
            'oliveMill.message.transferDowngraded' => ['Travaso registrato: la miscela è passata alla categoria inferiore.', 'The transfer was recorded: the blend moved to the lower category.', 'Die Umfüllung wurde erfasst: der Verschnitt wurde in die niedrigere Kategorie eingestuft.', 'Le transfert a été enregistré : le coupage est passé à la catégorie inférieure.'],
            'oliveMill.message.packaged' => ['Confezionamento registrato con il lotto generato.', 'Bottling recorded with the generated lot.', 'Abfüllung mit dem erzeugten Los erfasst.', 'Conditionnement enregistré avec le lot généré.'],
            'oliveMill.message.adjusted' => ['Rettifica registrata.', 'The rectification was recorded.', 'Die Berichtigung wurde erfasst.', 'La rectification a été enregistrée.'],
            'oliveMill.message.sianTransmitted' => ['{count} operazioni trasmesse al SIAN. Protocollo {protocol}.', '{count} operations sent to the SIAN. Protocol {protocol}.', '{count} Vorgänge an den SIAN übermittelt. Protokoll {protocol}.', '{count} opérations transmises au SIAN. Protocole {protocol}.'],
            'oliveMill.message.sianNotTransmitted' => ['Trasmissione al SIAN non riuscita ({count} operazioni valide).', 'Transmission to the SIAN failed ({count} valid operations).', 'Die Übermittlung an den SIAN ist fehlgeschlagen ({count} gültige Vorgänge).', 'La transmission au SIAN a échoué ({count} opérations valides).'],
            'oliveMill.message.sianExported' => ['File del registro scaricato.', 'The register file was downloaded.', 'Die Registerdatei wurde heruntergeladen.', 'Le fichier du registre a été téléchargé.'],
            'oliveMill.message.byproductRecorded' => ['Movimento di sottoprodotto registrato.', 'The by-product movement was recorded.', 'Die Nebenproduktbewegung wurde erfasst.', 'Le mouvement de sous-produit a été enregistré.'],
            'oliveMill.alert.sianOverdue' => ['{count} operazioni SIAN sono oltre il termine di 6 giorni.', '{count} SIAN operations are past the 6-day deadline.', '{count} SIAN-Vorgänge haben die 6-Tage-Frist überschritten.', '{count} opérations SIAN dépassent le délai de 6 jours.'],
            'oliveMill.alert.sianDueSoon' => ['{count} operazioni SIAN scadono a breve: trasmetterle.', '{count} SIAN operations are due soon: transmit them.', '{count} SIAN-Vorgänge sind bald fällig: übermitteln.', '{count} opérations SIAN arrivent à échéance : les transmettre.'],
            'oliveMill.alert.qualityWarnings' => ['Lotto {lot}: la qualità analizzata richiede attenzione.', 'Lot {lot}: the analysed quality needs attention.', 'Los {lot}: die analysierte Qualität erfordert Aufmerksamkeit.', 'Lot {lot} : la qualité analysée demande de l’attention.'],
            'oliveMill.alert.destinationMismatch' => ['Ritiro, molenda e stoccaggio devono sommare esattamente la quantità ottenuta.', 'Pick-up, molenda and storage must add up exactly to the quantity obtained.', 'Abholung, Molenda und Lagerung müssen genau der gewonnenen Menge entsprechen.', 'Retrait, molenda et stockage doivent totaliser exactement la quantité obtenue.'],
            'oliveMill.alert.tankRequired' => ['Scegliere la cisterna in cui caricare l’olio trattenuto.', 'Choose the tank where the retained oil is loaded.', 'Den Tank wählen, in den das einbehaltene Öl geladen wird.', 'Choisir la cuve où charger l’huile retenue.'],
            'oliveMill.alert.blendDowngrade' => ['Le due cisterne hanno categorie diverse: la miscela passerà alla categoria inferiore.', 'The two tanks hold different categories: the blend will move to the lower category.', 'Die beiden Tanks enthalten unterschiedliche Kategorien: der Verschnitt wird in die niedrigere Kategorie eingestuft.', 'Les deux cuves ont des catégories différentes : le coupage passera à la catégorie inférieure.'],
            'oliveMill.alert.blendBlocked' => ['Gli oli DOP, IGP e biologici non si miscelano con altre categorie.', 'DOP, IGP and organic oils are not blended with other categories.', 'DOP-, IGP- und Bio-Öle werden nicht mit anderen Kategorien verschnitten.', 'Les huiles DOP, IGP et biologiques ne sont pas coupées avec d’autres catégories.'],
            'oliveMill.alert.spreadingLimit' => ['Questo spandimento supera il limite annuo agronomico per ettaro.', 'This spreading exceeds the yearly agronomic limit per hectare.', 'Diese Ausbringung überschreitet die jährliche agronomische Grenze pro Hektar.', 'Cet épandage dépasse la limite agronomique annuelle par hectare.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function labels(): array
    {
        return [
            'oliveMill.label.yield' => ['Resa', 'Yield', 'Ausbeute', 'Rendement'],
            'oliveMill.label.coldExtraction' => ['Estratto a freddo', 'Cold extracted', 'Kalt extrahiert', 'Extrait à froid'],
            'oliveMill.label.toRoute' => ['Ancora da destinare', 'Still to route', 'Noch zu verteilen', 'Reste à répartir'],
            'oliveMill.label.noLine' => ['Senza linea', 'No line', 'Ohne Linie', 'Sans ligne'],
            'oliveMill.label.noZone' => ['Senza zona', 'No zone', 'Ohne Zone', 'Sans zone'],
            'oliveMill.label.serviceInvoice' => ['Fattura attiva del servizio di molitura', 'Active invoice of the milling service', 'Ausgangsrechnung der Lohnverarbeitung', 'Facture de vente du service de broyage'],
            'oliveMill.label.purchaseDocument' => ['Autofattura / acquisto dell’olio trattenuto', 'Self-invoice / purchase of the retained oil', 'Eigenrechnung / Kauf des einbehaltenen Öls', 'Autofacture / achat de l’huile retenue'],
            'oliveMill.label.cashBalance' => ['Saldo monetario netto', 'Net cash balance', 'Netto-Barsaldo', 'Solde net en espèces'],
            'oliveMill.label.compensation' => ['Compensazione contabile', 'Accounting offset', 'Buchhalterische Verrechnung', 'Compensation comptable'],
            'oliveMill.label.farmerPickup' => ['Olio restituito al conferente', 'Oil returned to the farmer', 'An den Landwirt zurückgegebenes Öl', 'Huile restituée à l’agriculteur'],
            'oliveMill.label.net' => ['Imponibile', 'Net', 'Netto', 'Net'],
            'oliveMill.label.vat' => ['IVA', 'VAT', 'MWST', 'TVA'],
            'oliveMill.label.totalLiters' => ['Litri totali', 'Total litres', 'Gesamtliter', 'Litres totaux'],
            'oliveMill.label.daysLeft' => ['{count} giorni', '{count} days left', 'noch {count} Tage', '{count} jours restants'],
            'oliveMill.label.daysOverdue' => ['Scaduta da {count} giorni', 'Overdue by {count} days', '{count} Tage überfällig', 'En retard de {count} jours'],
            'oliveMill.label.protocol' => ['Protocollo SIAN', 'SIAN protocol', 'SIAN-Protokoll', 'Protocole SIAN'],
            'oliveMill.label.transmittedAt' => ['Trasmessa il', 'Transmitted on', 'Übermittelt am', 'Transmise le'],
            'oliveMill.label.bestBefore' => ['Da consumarsi entro (TMC)', 'Best before', 'Mindestens haltbar bis', 'À consommer de préférence avant'],
            'oliveMill.label.perHectare' => ['Spandimento', 'Spreading', 'Ausbringung', 'Épandage'],
            'oliveMill.label.limit' => ['limite', 'limit', 'Grenze', 'limite'],
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
