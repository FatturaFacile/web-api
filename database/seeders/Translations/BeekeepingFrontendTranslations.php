<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the beekeeping and honey production module (Mod 296): apiaries with the GIS map and foraging circles, hives and
 * queens, glove-friendly inspections, the veterinary varroa register with the withdrawal lock, BDA / cantonal census and nomadism,
 * the honey room with HACCP traceability and the agricultural tax, plus the codes the API reports (alerts, warnings, notes, errors).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (BDA, VETINFO, TVD, TAMV, HACCP, HMF, TMC, IVA) stay untranslated. Placeholders are written {name}.
 */
final class BeekeepingFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::general(),
            self::fields(),
            self::screens(),
            self::enumerations(),
            self::codes(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.BEEKEEPING' => ['Apicoltura e miele', 'Beekeeping and honey', 'Imkerei und Honig', 'Apiculture et miel'],
            'NAV.BEEKEEPING_DASHBOARD' => ['Apiari e mappa', 'Apiaries and map', 'Bienenstände und Karte', 'Ruchers et carte'],
            'NAV.BEEKEEPING_HIVES' => ['Arnie e regine', 'Hives and queens', 'Völker und Königinnen', 'Ruches et reines'],
            'NAV.BEEKEEPING_VARROA' => ['Registro trattamenti varroa', 'Varroa treatment register', 'Varroa-Behandlungsjournal', 'Registre des traitements varroa'],
            'NAV.BEEKEEPING_COMPLIANCE' => ['Censimento BDA e nomadismo', 'BDA census and nomadism', 'BDA-Erhebung und Wanderung', 'Recensement BDA et transhumance'],
            'NAV.BEEKEEPING_HONEY' => ['Smielatura e tracciabilità', 'Honey room and traceability', 'Schleuderraum und Rückverfolgbarkeit', 'Miellerie et traçabilité'],
            'NAV.BEEKEEPING_TAX' => ['IVA agricola', 'Agricultural VAT', 'Landwirtschaftliche MWST', 'TVA agricole'],
            'PAGE_TITLE.BEEKEEPING_DASHBOARD' => ['Apicoltura: apiari, raggio di bottinatura e stato delle arnie', 'Beekeeping: apiaries, foraging radius and hive status', 'Imkerei: Bienenstände, Flugradius und Volksstatus', 'Apiculture : ruchers, rayon de butinage et état des ruches'],
            'PAGE_TITLE.BEEKEEPING_HIVES' => ['Arnie, nuclei e regine con visite rapide', 'Hives, nuclei and queens with quick inspections', 'Völker, Ableger und Königinnen mit Schnelldurchsichten', 'Ruches, nucléi et reines avec visites rapides'],
            'PAGE_TITLE.BEEKEEPING_VARROA' => ['Registro veterinario dei trattamenti anti-varroa', 'Veterinary anti-varroa treatment register', 'Tierärztliches Varroa-Behandlungsjournal', 'Registre vétérinaire des traitements anti-varroa'],
            'PAGE_TITLE.BEEKEEPING_COMPLIANCE' => ['Censimento annuale BDA / cantonale e movimentazioni', 'Annual BDA / cantonal census and movements', 'Jährliche BDA- / kantonale Erhebung und Verschiebungen', 'Recensement annuel BDA / cantonal et mouvements'],
            'PAGE_TITLE.BEEKEEPING_HONEY' => ['Smielatura, lotti HACCP, invasettamento e tracciabilità', 'Honey room, HACCP lots, jarring and traceability', 'Schleuderraum, HACCP-Lose, Abfüllung und Rückverfolgbarkeit', 'Miellerie, lots HACCP, mise en pots et traçabilité'],
            'PAGE_TITLE.BEEKEEPING_TAX' => ['IVA agricola: Art. 34 DPR 633/72 e aliquota ridotta svizzera', 'Agricultural VAT: Art. 34 DPR 633/72 and the Swiss reduced rate', 'Landwirtschaftliche MWST: Art. 34 DPR 633/72 und reduzierter Schweizer Satz', 'TVA agricole : art. 34 DPR 633/72 et taux réduit suisse'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function general(): array
    {
        return [
            'beekeeping.action.all' => ['Tutti', 'All', 'Alle', 'Tous'],
            'beekeeping.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'beekeeping.action.confirmDelete' => ['Eliminare questo elemento?', 'Delete this item?', 'Dieses Element löschen?', 'Supprimer cet élément ?'],
            'beekeeping.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'beekeeping.action.refresh' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'beekeeping.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'beekeeping.common.yes' => ['Sì', 'Yes', 'Ja', 'Oui'],
            'beekeeping.common.no' => ['No', 'No', 'Nein', 'Non'],
            'beekeeping.message.saved' => ['Dati salvati.', 'Data saved.', 'Daten gespeichert.', 'Données enregistrées.'],
            'beekeeping.message.deleted' => ['Elemento eliminato.', 'Item deleted.', 'Element gelöscht.', 'Élément supprimé.'],
            'beekeeping.message.inspectionSaved' => ['Visita registrata.', 'Inspection recorded.', 'Durchsicht erfasst.', 'Visite enregistrée.'],
            'beekeeping.message.treatmentRegistered' => ['Trattamento registrato: tempo di sospensione calcolato.', 'Treatment registered: withdrawal period calculated.', 'Behandlung erfasst: Wartezeit berechnet.', 'Traitement enregistré : délai d’attente calculé.'],
            'beekeeping.message.movementCreated' => ['Movimentazione pianificata.', 'Movement planned.', 'Verschiebung geplant.', 'Déplacement planifié.'],
            'beekeeping.message.censusGenerated' => ['Censimento generato.', 'Census generated.', 'Erhebung erstellt.', 'Recensement généré.'],
            'beekeeping.message.censusSubmitted' => ['Censimento registrato come presentato.', 'Census recorded as submitted.', 'Erhebung als eingereicht erfasst.', 'Recensement enregistré comme déposé.'],
            'beekeeping.message.intakeRecorded' => ['Ingresso melari registrato.', 'Super intake recorded.', 'Honigraum-Eingang erfasst.', 'Entrée de hausses enregistrée.'],
            'beekeeping.message.batchCreated' => ['Lotto di smielatura creato.', 'Extraction batch created.', 'Schleuderlos erstellt.', 'Lot d’extraction créé.'],
            'beekeeping.message.analysisRecorded' => ['Analisi registrata.', 'Analysis recorded.', 'Analyse erfasst.', 'Analyse enregistrée.'],
            'beekeeping.message.jarringRecorded' => ['Invasettamento registrato e magazzino scaricato.', 'Jarring recorded and stock deducted.', 'Abfüllung erfasst und Lager abgebucht.', 'Mise en pots enregistrée et stock déduit.'],
            'beekeeping.column.frames' => ['Covata / scorte', 'Brood / stores', 'Brut / Futter', 'Couvain / réserves'],
            'beekeeping.column.hives' => ['Arnie', 'Hives', 'Völker', 'Ruches'],
            'beekeeping.column.nuclei' => ['Nuclei', 'Nuclei', 'Ableger', 'Nucléi'],
            'beekeeping.column.queen' => ['Regina', 'Queen', 'Königin', 'Reine'],
            'beekeeping.column.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'beekeeping.column.window' => ['Finestra di presentazione', 'Submission window', 'Einreichungsfenster', 'Fenêtre de dépôt'],
            'beekeeping.column.withdrawalEnd' => ['Fine sospensione', 'Withdrawal end', 'Ende der Wartezeit', 'Fin du délai d’attente'],
            'beekeeping.column.yield' => ['Resa per arnia (kg)', 'Yield per hive (kg)', 'Ertrag pro Volk (kg)', 'Rendement par ruche (kg)'],
            'beekeeping.badge.lockedUntil' => ['Bloccata fino al {until}', 'Locked until {until}', 'Gesperrt bis {until}', 'Bloquée jusqu’au {until}'],
            'beekeeping.badge.overlap' => ['{count} sovrapposizioni', '{count} overlaps', '{count} Überschneidungen', '{count} chevauchements'],
            'beekeeping.badge.withdrawal' => ['{count} in sospensione', '{count} in withdrawal', '{count} in Wartezeit', '{count} en délai d’attente'],
            'beekeeping.badge.withdrawalActive' => ['Sospensione attiva', 'Withdrawal active', 'Wartezeit aktiv', 'Délai d’attente actif'],
            'beekeeping.error.generic' => ['Operazione non riuscita. Riprova.', 'The operation failed. Try again.', 'Der Vorgang ist fehlgeschlagen. Bitte erneut versuchen.', 'L’opération a échoué. Réessayez.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'beekeeping.field.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'beekeeping.field.altitude' => ['Altitudine (m)', 'Altitude (m)', 'Höhe (m)', 'Altitude (m)'],
            'beekeeping.field.annualTurnover' => ['Volume d’affari annuo', 'Annual turnover', 'Jahresumsatz', 'Chiffre d’affaires annuel'],
            'beekeeping.field.apiary' => ['Apiario', 'Apiary', 'Bienenstand', 'Rucher'],
            'beekeeping.field.apiaryType' => ['Tipo di apiario', 'Apiary type', 'Standtyp', 'Type de rucher'],
            'beekeeping.field.applicationMethod' => ['Metodo di applicazione', 'Application method', 'Anwendungsmethode', 'Méthode d’application'],
            'beekeeping.field.bestBefore' => ['TMC', 'Best before (TMC)', 'Mindestens haltbar bis', 'À consommer de préférence avant'],
            'beekeeping.field.bloomingCalendar' => ['Calendario delle fioriture', 'Blooming calendar', 'Blühkalender', 'Calendrier des floraisons'],
            'beekeeping.field.botanicalOrigin' => ['Origine botanica', 'Botanical origin', 'Botanische Herkunft', 'Origine botanique'],
            'beekeeping.field.broodFrames' => ['Telaini di covata', 'Brood frames', 'Brutwaben', 'Cadres de couvain'],
            'beekeeping.field.canton' => ['Cantone', 'Canton', 'Kanton', 'Canton'],
            'beekeeping.field.capProduct' => ['Articolo capsula (ID)', 'Cap product (ID)', 'Deckelartikel (ID)', 'Article capsule (ID)'],
            'beekeeping.field.cappedBrood' => ['Covata opercolata', 'Capped brood', 'Verdeckelte Brut', 'Couvain operculé'],
            'beekeeping.field.censusYear' => ['Anno del censimento', 'Census year', 'Erhebungsjahr', 'Année du recensement'],
            'beekeeping.field.clipped' => ['Ala tagliata', 'Clipped wing', 'Flügel beschnitten', 'Aile coupée'],
            'beekeeping.field.code' => ['Codice', 'Code', 'Code', 'Code'],
            'beekeeping.field.commercialName' => ['Nome commerciale', 'Commercial name', 'Handelsname', 'Nom commercial'],
            'beekeeping.field.countType' => ['Tipo di conteggio', 'Count type', 'Zählart', 'Type de comptage'],
            'beekeeping.field.countedOn' => ['Data del conteggio', 'Count date', 'Zähldatum', 'Date du comptage'],
            'beekeeping.field.counterpart' => ['Controparte', 'Counterpart', 'Gegenpartei', 'Contrepartie'],
            'beekeeping.field.country' => ['Paese', 'Country', 'Land', 'Pays'],
            'beekeeping.field.decantedOn' => ['Data di decantazione', 'Decanting date', 'Abklärdatum', 'Date de décantation'],
            'beekeeping.field.departureDate' => ['Data di partenza', 'Departure date', 'Abfahrtsdatum', 'Date de départ'],
            'beekeeping.field.destinationApiary' => ['Apiario di destinazione', 'Destination apiary', 'Zielstand', 'Rucher de destination'],
            'beekeeping.field.destinationLabel' => ['Destinazione (pascolo)', 'Destination (pasture)', 'Ziel (Weide)', 'Destination (pâturage)'],
            'beekeeping.field.documentNumber' => ['N. documento', 'Document no.', 'Dokumentnr.', 'N° du document'],
            'beekeeping.field.dosage' => ['Dosaggio', 'Dosage', 'Dosierung', 'Dosage'],
            'beekeeping.field.drugBatch' => ['Lotto del farmaco', 'Drug batch', 'Arzneimittel-Charge', 'Lot du médicament'],
            'beekeeping.field.eggs' => ['Uova', 'Eggs', 'Eier', 'Œufs'],
            'beekeeping.field.extractedKg' => ['Miele estratto (kg)', 'Extracted honey (kg)', 'Geschleuderter Honig (kg)', 'Miel extrait (kg)'],
            'beekeeping.field.extractedOn' => ['Data di smielatura', 'Extraction date', 'Schleuderdatum', 'Date d’extraction'],
            'beekeeping.field.feedingKg' => ['Quantità (kg)', 'Quantity (kg)', 'Menge (kg)', 'Quantité (kg)'],
            'beekeeping.field.feedingType' => ['Nutrizione', 'Feeding', 'Fütterung', 'Nourrissement'],
            'beekeeping.field.flora' => ['Flora prevalente', 'Prevalent flora', 'Vorherrschende Flora', 'Flore dominante'],
            'beekeeping.field.foragingRadius' => ['Raggio di bottinatura (m)', 'Foraging radius (m)', 'Flugradius (m)', 'Rayon de butinage (m)'],
            'beekeeping.field.geneticLine' => ['Linea genetica', 'Genetic line', 'Zuchtlinie', 'Lignée génétique'],
            'beekeeping.field.grossWeight' => ['Peso lordo (kg)', 'Gross weight (kg)', 'Bruttogewicht (kg)', 'Poids brut (kg)'],
            'beekeeping.field.harvestedOn' => ['Data di raccolta', 'Harvest date', 'Erntedatum', 'Date de récolte'],
            'beekeeping.field.hive' => ['Arnia', 'Hive', 'Volk', 'Ruche'],
            'beekeeping.field.hiveType' => ['Tipo di arnia', 'Hive type', 'Beutentyp', 'Type de ruche'],
            'beekeeping.field.hives' => ['Arnie', 'Hives', 'Völker', 'Ruches'],
            'beekeeping.field.hmf' => ['HMF (mg/kg)', 'HMF (mg/kg)', 'HMF (mg/kg)', 'HMF (mg/kg)'],
            'beekeeping.field.installedOn' => ['Data di installazione', 'Installation date', 'Aufstelldatum', 'Date d’installation'],
            'beekeeping.field.intakes' => ['Ingressi melari', 'Super intakes', 'Honigraum-Eingänge', 'Entrées de hausses'],
            'beekeeping.field.jarFormat' => ['Formato del vasetto', 'Jar format', 'Glasformat', 'Format du pot'],
            'beekeeping.field.jarProduct' => ['Articolo vasetto (ID)', 'Jar product (ID)', 'Glasartikel (ID)', 'Article pot (ID)'],
            'beekeeping.field.jarredOn' => ['Data di invasettamento', 'Jarring date', 'Abfülldatum', 'Date de mise en pots'],
            'beekeeping.field.jarsCount' => ['Numero di vasetti', 'Number of jars', 'Anzahl Gläser', 'Nombre de pots'],
            'beekeeping.field.kind' => ['Tipo', 'Type', 'Art', 'Type'],
            'beekeeping.field.labelProduct' => ['Articolo etichetta (ID)', 'Label product (ID)', 'Etikettenartikel (ID)', 'Article étiquette (ID)'],
            'beekeeping.field.larvae' => ['Larve', 'Larvae', 'Larven', 'Larves'],
            'beekeeping.field.latitude' => ['Latitudine', 'Latitude', 'Breitengrad', 'Latitude'],
            'beekeeping.field.longitude' => ['Longitudine', 'Longitude', 'Längengrad', 'Longitude'],
            'beekeeping.field.lot' => ['Lotto', 'Lot', 'Los', 'Lot'],
            'beekeeping.field.mitesPerDay' => ['Varroe al giorno', 'Mites per day', 'Milben pro Tag', 'Varroas par jour'],
            'beekeeping.field.moisture' => ['Umidità (%)', 'Moisture (%)', 'Wassergehalt (%)', 'Humidité (%)'],
            'beekeeping.field.municipality' => ['Comune', 'Municipality', 'Gemeinde', 'Commune'],
            'beekeeping.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'beekeeping.field.netAmount' => ['Importo netto', 'Net amount', 'Nettobetrag', 'Montant net'],
            'beekeeping.field.netWeight' => ['Peso netto (kg)', 'Net weight (kg)', 'Nettogewicht (kg)', 'Poids net (kg)'],
            'beekeeping.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'beekeeping.field.observations' => ['Osservazioni sanitarie', 'Sanitary observations', 'Gesundheitsbeobachtungen', 'Observations sanitaires'],
            'beekeeping.field.organoleptic' => ['Note organolettiche e polliniche', 'Organoleptic and pollen notes', 'Sensorische und Pollennotizen', 'Notes organoleptiques et polliniques'],
            'beekeeping.field.origin' => ['Origine', 'Origin', 'Herkunft', 'Origine'],
            'beekeeping.field.originApiary' => ['Apiario di origine', 'Origin apiary', 'Herkunftsstand', 'Rucher d’origine'],
            'beekeeping.field.pedigree' => ['Pedigree', 'Pedigree', 'Stammbaum', 'Pedigree'],
            'beekeeping.field.prescription' => ['Ricetta veterinaria', 'Veterinary prescription', 'Tierärztliche Verschreibung', 'Ordonnance vétérinaire'],
            'beekeeping.field.product' => ['Prodotto', 'Product', 'Produkt', 'Produit'],
            'beekeeping.field.productKind' => ['Tipo di prodotto', 'Product kind', 'Produktart', 'Type de produit'],
            'beekeeping.field.province' => ['Provincia', 'Province', 'Provinz', 'Province'],
            'beekeeping.field.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'beekeeping.field.queenBreeder' => ['Allevatore', 'Breeder', 'Züchter', 'Éleveur'],
            'beekeeping.field.queenSeen' => ['Regina vista', 'Queen seen', 'Königin gesehen', 'Reine vue'],
            'beekeeping.field.queenStatus' => ['Stato della regina', 'Queen status', 'Königinnenstatus', 'Statut de la reine'],
            'beekeeping.field.queenYear' => ['Anno della regina', 'Queen year', 'Königinnenjahrgang', 'Année de la reine'],
            'beekeeping.field.regime' => ['Regime', 'Regime', 'Regime', 'Régime'],
            'beekeeping.field.registryCodeCh' => ['N. Bienenstand / TVD', 'Bienenstand no. / TVD', 'Bienenstand-Nr. / TVD', 'N° de rucher / TVD'],
            'beekeeping.field.registryCodeIt' => ['Codice apiario BDA', 'BDA apiary code', 'BDA-Standcode', 'Code rucher BDA'],
            'beekeeping.field.returnDate' => ['Data di rientro', 'Return date', 'Rückkehrdatum', 'Date de retour'],
            'beekeeping.field.saleChannel' => ['Canale di vendita', 'Sale channel', 'Verkaufskanal', 'Canal de vente'],
            'beekeeping.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'beekeeping.field.storeFrames' => ['Telaini di scorte', 'Store frames', 'Futterwaben', 'Cadres de réserves'],
            'beekeeping.field.strength' => ['Forza della colonia (1-5)', 'Colony strength (1-5)', 'Volksstärke (1-5)', 'Force de la colonie (1-5)'],
            'beekeeping.field.subject' => ['Oggetto', 'Subject', 'Gegenstand', 'Objet'],
            'beekeeping.field.submissionReference' => ['Riferimento di presentazione', 'Submission reference', 'Einreichungsreferenz', 'Référence de dépôt'],
            'beekeeping.field.supers' => ['Melari', 'Supers', 'Honigräume', 'Hausses'],
            'beekeeping.field.supersAdded' => ['Melari aggiunti', 'Supers added', 'Honigräume aufgesetzt', 'Hausses ajoutées'],
            'beekeeping.field.supersRemoved' => ['Melari rimossi', 'Supers removed', 'Honigräume entfernt', 'Hausses retirées'],
            'beekeeping.field.swarmCellsDestroyed' => ['Celle distrutte', 'Cells destroyed', 'Zellen zerstört', 'Cellules détruites'],
            'beekeeping.field.swarmCellsFound' => ['Celle di sciamatura', 'Swarm cells', 'Schwarmzellen', 'Cellules d’essaimage'],
            'beekeeping.field.tank' => ['Maturatore / fusto', 'Maturator / drum', 'Reifetank / Fass', 'Maturateur / fût'],
            'beekeeping.field.targetFlora' => ['Fioritura obiettivo', 'Target flora', 'Zieltracht', 'Floraison cible'],
            'beekeeping.field.temperament' => ['Temperamento', 'Temperament', 'Sanftmut', 'Tempérament'],
            'beekeeping.field.temperature' => ['Temperatura (°C)', 'Temperature (°C)', 'Temperatur (°C)', 'Température (°C)'],
            'beekeeping.field.treatmentDate' => ['Data del trattamento', 'Treatment date', 'Behandlungsdatum', 'Date du traitement'],
            'beekeeping.field.vehiclePlate' => ['Targa del veicolo', 'Vehicle plate', 'Fahrzeugkennzeichen', 'Plaque du véhicule'],
            'beekeeping.field.veterinarian' => ['Veterinario', 'Veterinarian', 'Tierarzt', 'Vétérinaire'],
            'beekeeping.field.warehouse' => ['Magazzino (ID)', 'Warehouse (ID)', 'Lager (ID)', 'Entrepôt (ID)'],
            'beekeeping.field.weather' => ['Meteo', 'Weather', 'Wetter', 'Météo'],
            'beekeeping.field.wholeApiary' => ['Intero apiario', 'Whole apiary', 'Ganzer Bienenstand', 'Tout le rucher'],
            'beekeeping.field.withdrawalDays' => ['Sospensione (giorni)', 'Withdrawal (days)', 'Wartezeit (Tage)', 'Délai d’attente (jours)'],
            'beekeeping.field.year' => ['Anno', 'Year', 'Jahr', 'Année'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function screens(): array
    {
        return [
            'beekeeping.summary.apiaries' => ['Apiari attivi', 'Active apiaries', 'Aktive Bienenstände', 'Ruchers actifs'],
            'beekeeping.summary.hives' => ['Arnie', 'Hives', 'Völker', 'Ruches'],
            'beekeeping.summary.nuclei' => ['Nuclei', 'Nuclei', 'Ableger', 'Nucléi'],
            'beekeeping.summary.orphaned' => ['Colonie orfane', 'Orphaned colonies', 'Weisellose Völker', 'Colonies orphelines'],
            'beekeeping.summary.withdrawals' => ['Sospensioni attive', 'Active withdrawals', 'Aktive Wartezeiten', 'Délais d’attente actifs'],
            'beekeeping.summary.maturation' => ['Lotti in maturazione', 'Batches maturing', 'Lose in Reifung', 'Lots en maturation'],
            'beekeeping.map.title' => ['Mappa degli apiari', 'Apiary map', 'Karte der Bienenstände', 'Carte des ruchers'],
            'beekeeping.map.fit' => ['Mostra tutti', 'Fit all', 'Alle zeigen', 'Tout afficher'],
            'beekeeping.map.zoomIn' => ['Ingrandisci', 'Zoom in', 'Vergrössern', 'Agrandir'],
            'beekeeping.map.zoomOut' => ['Riduci', 'Zoom out', 'Verkleinern', 'Réduire'],
            'beekeeping.map.legendRadius' => ['Raggio di bottinatura', 'Foraging radius', 'Flugradius', 'Rayon de butinage'],
            'beekeeping.map.legendOverlap' => ['Raggi sovrapposti: stessa flora e rischio varroa condiviso', 'Overlapping radii: shared flora and varroa risk', 'Überschneidende Radien: gemeinsame Flora und Varroa-Risiko', 'Rayons qui se chevauchent : flore et risque varroa partagés'],
            'beekeeping.map.legendHives' => ['Numero di arnie (rosso: sospensione attiva)', 'Number of hives (red: withdrawal active)', 'Anzahl Völker (rot: Wartezeit aktiv)', 'Nombre de ruches (rouge : délai d’attente actif)'],
            'beekeeping.map.unlocated' => ['{count} apiari senza coordinate non compaiono sulla mappa.', '{count} apiaries without coordinates are not on the map.', '{count} Bienenstände ohne Koordinaten erscheinen nicht auf der Karte.', '{count} ruchers sans coordonnées ne figurent pas sur la carte.'],
            'beekeeping.apiary.new' => ['Nuovo apiario', 'New apiary', 'Neuer Bienenstand', 'Nouveau rucher'],
            'beekeeping.apiary.formTitleNew' => ['Nuovo apiario', 'New apiary', 'Neuer Bienenstand', 'Nouveau rucher'],
            'beekeeping.apiary.formTitleEdit' => ['Modifica apiario', 'Edit apiary', 'Bienenstand bearbeiten', 'Modifier le rucher'],
            'beekeeping.apiary.empty' => ['Nessun apiario registrato.', 'No apiary registered.', 'Kein Bienenstand erfasst.', 'Aucun rucher enregistré.'],
            'beekeeping.hive.new' => ['Nuova arnia', 'New hive', 'Neues Volk', 'Nouvelle ruche'],
            'beekeeping.hive.formTitleNew' => ['Nuova arnia o nucleo', 'New hive or nucleus', 'Neues Volk oder Ableger', 'Nouvelle ruche ou nucléus'],
            'beekeeping.hive.formTitleEdit' => ['Modifica arnia', 'Edit hive', 'Volk bearbeiten', 'Modifier la ruche'],
            'beekeeping.hive.empty' => ['Nessuna arnia trovata.', 'No hive found.', 'Kein Volk gefunden.', 'Aucune ruche trouvée.'],
            'beekeeping.hive.qrSheet' => ['Etichette QR (PDF)', 'QR labels (PDF)', 'QR-Etiketten (PDF)', 'Étiquettes QR (PDF)'],
            'beekeeping.hive.queenSection' => ['Regina', 'Queen', 'Königin', 'Reine'],
            'beekeeping.hive.colonySection' => ['Colonia', 'Colony', 'Volk', 'Colonie'],
            'beekeeping.hive.lockedTitle' => ['Posa melari bloccata', 'Super placement blocked', 'Aufsetzen von Honigräumen gesperrt', 'Pose de hausses bloquée'],
            'beekeeping.hive.lockedBody' => ['L’arnia è in sospensione fino al {until} ({product}).', 'The hive is in withdrawal until {until} ({product}).', 'Das Volk ist bis {until} in Wartezeit ({product}).', 'La ruche est en délai d’attente jusqu’au {until} ({product}).'],
            'beekeeping.hive.lockBanner' => ['{count} arnie in sospensione: niente melari né smielatura.', '{count} hives in withdrawal: no supers and no harvest.', '{count} Völker in Wartezeit: keine Honigräume und keine Ernte.', '{count} ruches en délai d’attente : ni hausses ni récolte.'],
            'beekeeping.inspection.title' => ['Visita rapida (modalità guanti)', 'Quick inspection (glove mode)', 'Schnelldurchsicht (Handschuhmodus)', 'Visite rapide (mode gants)'],
            'beekeeping.inspection.start' => ['Visita ({count})', 'Inspect ({count})', 'Durchsicht ({count})', 'Visite ({count})'],
            'beekeeping.inspection.save' => ['Registra visita', 'Record inspection', 'Durchsicht erfassen', 'Enregistrer la visite'],
            'beekeeping.inspection.increase' => ['Aumenta', 'Increase', 'Erhöhen', 'Augmenter'],
            'beekeeping.inspection.decrease' => ['Diminuisci', 'Decrease', 'Verringern', 'Diminuer'],
            'beekeeping.inspection.alertsPreview' => ['Allerte che questa visita genererà:', 'Alerts this inspection will raise:', 'Warnungen dieser Durchsicht:', 'Alertes que cette visite déclenchera :'],
            'beekeeping.inspection.lockedNote' => ['Arnie in sospensione ({hives}): non si possono posare melari.', 'Hives in withdrawal ({hives}): supers cannot be placed.', 'Völker in Wartezeit ({hives}): Honigräume dürfen nicht aufgesetzt werden.', 'Ruches en délai d’attente ({hives}) : les hausses ne peuvent pas être posées.'],
            'beekeeping.varroa.newTitle' => ['Nuovo trattamento veterinario', 'New veterinary treatment', 'Neue tierärztliche Behandlung', 'Nouveau traitement vétérinaire'],
            'beekeeping.varroa.register' => ['Registra trattamento', 'Register treatment', 'Behandlung erfassen', 'Enregistrer le traitement'],
            'beekeeping.varroa.registerPdf' => ['Registro ufficiale (PDF)', 'Official register (PDF)', 'Offizielles Journal (PDF)', 'Registre officiel (PDF)'],
            'beekeeping.varroa.locksTitle' => ['Arnie bloccate dalla sospensione: niente melari né smielatura', 'Hives locked by the withdrawal: no supers and no harvest', 'Durch die Wartezeit gesperrte Völker: keine Honigräume und keine Ernte', 'Ruches bloquées par le délai d’attente : ni hausses ni récolte'],
            'beekeeping.varroa.daysLeft' => ['{count} giorni', '{count} days left', 'noch {count} Tage', '{count} jours restants'],
            'beekeeping.varroa.endPreview' => ['Fine della sospensione: {date}', 'Withdrawal ends: {date}', 'Ende der Wartezeit: {date}', 'Fin du délai d’attente : {date}'],
            'beekeeping.varroa.someHives' => ['Arnie scelte', 'Selected hives', 'Ausgewählte Völker', 'Ruches choisies'],
            'beekeeping.varroa.empty' => ['Nessun trattamento registrato.', 'No treatment registered.', 'Keine Behandlung erfasst.', 'Aucun traitement enregistré.'],
            'beekeeping.varroa.countsTitle' => ['Caduta delle varroe', 'Mite drop', 'Milbenfall', 'Chute des varroas'],
            'beekeeping.varroa.addCount' => ['Registra conteggio', 'Record count', 'Zählung erfassen', 'Enregistrer le comptage'],
            'beekeeping.compliance.censusTab' => ['Censimento annuale', 'Annual census', 'Jährliche Erhebung', 'Recensement annuel'],
            'beekeeping.compliance.movementsTab' => ['Movimentazioni', 'Movements', 'Verschiebungen', 'Mouvements'],
            'beekeeping.compliance.generateCensus' => ['Genera censimento', 'Generate census', 'Erhebung erstellen', 'Générer le recensement'],
            'beekeeping.compliance.censusEmpty' => ['Nessun censimento generato.', 'No census generated.', 'Keine Erhebung erstellt.', 'Aucun recensement généré.'],
            'beekeeping.compliance.windowOpen' => ['Finestra aperta', 'Window open', 'Fenster offen', 'Fenêtre ouverte'],
            'beekeeping.compliance.submit' => ['Registra presentazione', 'Record submission', 'Einreichung erfassen', 'Enregistrer le dépôt'],
            'beekeeping.compliance.newMovement' => ['Nuova movimentazione', 'New movement', 'Neue Verschiebung', 'Nouveau mouvement'],
            'beekeeping.compliance.planMovement' => ['Pianifica', 'Plan', 'Planen', 'Planifier'],
            'beekeeping.compliance.document' => ['Documento di accompagnamento', 'Accompanying document', 'Begleitdokument', 'Document d’accompagnement'],
            'beekeeping.compliance.depart' => ['Parte', 'Depart', 'Abfahrt', 'Départ'],
            'beekeeping.compliance.arrive' => ['Arrivato', 'Arrived', 'Angekommen', 'Arrivé'],
            'beekeeping.honey.intakeTab' => ['Ingresso melari', 'Super intake', 'Honigraum-Eingang', 'Entrée de hausses'],
            'beekeeping.honey.batchTab' => ['Lotti di smielatura', 'Extraction batches', 'Schleuderlose', 'Lots d’extraction'],
            'beekeeping.honey.newIntake' => ['Nuovo ingresso melari', 'New super intake', 'Neuer Honigraum-Eingang', 'Nouvelle entrée de hausses'],
            'beekeeping.honey.saveIntake' => ['Registra ingresso', 'Record intake', 'Eingang erfassen', 'Enregistrer l’entrée'],
            'beekeeping.honey.newBatch' => ['Nuovo lotto di smielatura', 'New extraction batch', 'Neues Schleuderlos', 'Nouveau lot d’extraction'],
            'beekeeping.honey.createBatch' => ['Crea lotto HACCP', 'Create HACCP lot', 'HACCP-Los erstellen', 'Créer le lot HACCP'],
            'beekeeping.honey.pending' => ['Da smielare', 'To extract', 'Zu schleudern', 'À extraire'],
            'beekeeping.honey.extracted' => ['Smielato', 'Extracted', 'Geschleudert', 'Extrait'],
            'beekeeping.honey.remaining' => ['residuo {kg} kg', '{kg} kg left', 'Rest {kg} kg', 'reste {kg} kg'],
            'beekeeping.honey.analysis' => ['Maturazione e analisi', 'Maturation and analysis', 'Reifung und Analyse', 'Maturation et analyse'],
            'beekeeping.honey.jarring' => ['Invasettamento', 'Jarring', 'Abfüllung', 'Mise en pots'],
            'beekeeping.honey.jarringWeight' => ['Miele da invasettare: {kg} kg', 'Honey to jar: {kg} kg', 'Abzufüllender Honig: {kg} kg', 'Miel à mettre en pots : {kg} kg'],
            'beekeeping.honey.label' => ['Etichetta', 'Label', 'Etikett', 'Étiquette'],
            'beekeeping.honey.empty' => ['Nessun lotto di smielatura.', 'No extraction batch.', 'Kein Schleuderlos.', 'Aucun lot d’extraction.'],
            'beekeeping.trace.tab' => ['Tracciabilità HACCP', 'HACCP traceability', 'HACCP-Rückverfolgbarkeit', 'Traçabilité HACCP'],
            'beekeeping.trace.lotCode' => ['Codice lotto vasetti', 'Jar lot code', 'Glaslos-Code', 'Code du lot de pots'],
            'beekeeping.trace.backward' => ['Risali dal lotto', 'Trace back', 'Zurückverfolgen', 'Remonter'],
            'beekeeping.trace.forward' => ['Segui in avanti', 'Trace forward', 'Vorwärts verfolgen', 'Suivre en aval'],
            'beekeeping.trace.jarLot' => ['Lotto vasetti', 'Jar lot', 'Glaslos', 'Lot de pots'],
            'beekeeping.trace.batch' => ['Lotto di smielatura', 'Extraction batch', 'Schleuderlos', 'Lot d’extraction'],
            'beekeeping.trace.intake' => ['Raccolta', 'Harvest', 'Ernte', 'Récolte'],
            'beekeeping.trace.apiary' => ['Apiario', 'Apiary', 'Bienenstand', 'Rucher'],
            'beekeeping.trace.noBatches' => ['Nessun lotto collegato.', 'No batch linked.', 'Kein Los verknüpft.', 'Aucun lot lié.'],
            'beekeeping.trace.withdrawalRespected' => ['Sospensione rispettata', 'Withdrawal respected', 'Wartezeit eingehalten', 'Délai d’attente respecté'],
            'beekeeping.trace.withdrawalViolated' => ['Raccolta in sospensione', 'Harvest during withdrawal', 'Ernte während der Wartezeit', 'Récolte pendant le délai d’attente'],
            'beekeeping.tax.profileTitle' => ['Profilo fiscale del produttore agricolo', 'Agricultural producer tax profile', 'Steuerprofil des landwirtschaftlichen Produzenten', 'Profil fiscal du producteur agricole'],
            'beekeeping.tax.swissNote' => ['Svizzera: aliquota ridotta del 2,6% su miele e prodotti commestibili dell’alveare (vendita diretta e ingrosso), aliquota normale dell’8,1% sul resto.', 'Switzerland: reduced rate of 2.6% on honey and edible bee products (direct and wholesale sales), standard rate of 8.1% on the rest.', 'Schweiz: reduzierter Satz von 2,6% auf Honig und essbare Bienenprodukte (Direktverkauf und Grosshandel), Normalsatz von 8,1% auf den Rest.', 'Suisse : taux réduit de 2,6 % sur le miel et les produits comestibles de la ruche (vente directe et gros), taux normal de 8,1 % sur le reste.'],
            'beekeeping.tax.quoteTitle' => ['Calcolo IVA di una vendita', 'VAT of a sale', 'MWST-Berechnung eines Verkaufs', 'Calcul de la TVA d’une vente'],
            'beekeeping.tax.quote' => ['Calcola', 'Calculate', 'Berechnen', 'Calculer'],
            'beekeeping.tax.rate' => ['Aliquota applicata', 'Applied rate', 'Angewandter Satz', 'Taux appliqué'],
            'beekeeping.tax.vat' => ['IVA', 'VAT', 'MWST', 'TVA'],
            'beekeeping.tax.gross' => ['Totale', 'Total', 'Total', 'Total'],
            'beekeeping.tax.inputDeductible' => ['IVA sugli acquisti detraibile', 'Input VAT deductible', 'Vorsteuer abziehbar', 'TVA en amont déductible'],
            'beekeeping.tax.inputNotDeductible' => ['IVA sugli acquisti non detraibile (compensazione forfettaria)', 'Input VAT not deductible (flat-rate compensation)', 'Vorsteuer nicht abziehbar (Pauschalkompensation)', 'TVA en amont non déductible (compensation forfaitaire)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        $groups = [
            'country' => ['IT' => ['Italia', 'Italy', 'Italien', 'Italie'], 'CH' => ['Svizzera', 'Switzerland', 'Schweiz', 'Suisse']],
            'apiaryType' => ['stationary' => ['Stanziale', 'Stationary', 'Standimkerei', 'Sédentaire'], 'nomadic' => ['Nomade', 'Nomadic', 'Wanderimkerei', 'Transhumant']],
            'hiveType' => [
                'dadant_blatt' => ['Dadant-Blatt', 'Dadant-Blatt', 'Dadant-Blatt', 'Dadant-Blatt'],
                'langstroth' => ['Langstroth', 'Langstroth', 'Langstroth', 'Langstroth'],
                'top_bar' => ['Top-Bar', 'Top-Bar', 'Top-Bar', 'Top-Bar'],
                'nucleus' => ['Nucleo / polistirolo', 'Nucleus / polystyrene', 'Ableger / Styropor', 'Nucléus / polystyrène'],
                'mating_nucleus' => ['Arnia da fecondazione', 'Mating nucleus', 'Begattungskästchen', 'Ruchette de fécondation'],
            ],
            'hiveOrigin' => ['swarm_capture' => ['Cattura di sciame', 'Swarm capture', 'Schwarmfang', 'Capture d’essaim'], 'split' => ['Divisione / nucleo', 'Split / nucleus', 'Teilung / Ableger', 'Division / nucléus'], 'purchase' => ['Acquisto', 'Purchase', 'Kauf', 'Achat']],
            'hiveStatus' => [
                'active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
                'orphaned' => ['Orfana', 'Orphaned', 'Weisellos', 'Orpheline'],
                'queenless_cell' => ['Con cella reale', 'Queen cell', 'Mit Weiselzelle', 'Avec cellule royale'],
                'united' => ['Unita', 'United', 'Vereinigt', 'Réunie'],
                'dead' => ['Morta', 'Dead', 'Abgestorben', 'Morte'],
                'sold' => ['Venduta', 'Sold', 'Verkauft', 'Vendue'],
            ],
            'queenColor' => ['white' => ['Bianco', 'White', 'Weiss', 'Blanc'], 'yellow' => ['Giallo', 'Yellow', 'Gelb', 'Jaune'], 'red' => ['Rosso', 'Red', 'Rot', 'Rouge'], 'green' => ['Verde', 'Green', 'Grün', 'Vert'], 'blue' => ['Blu', 'Blue', 'Blau', 'Bleu']],
            'queenStatus' => [
                'present_laying' => ['Presente e in ovideposizione', 'Present and laying', 'Vorhanden und legend', 'Présente et pondeuse'],
                'virgin' => ['Vergine', 'Virgin', 'Unbegattet', 'Vierge'],
                'supersedure' => ['In sostituzione', 'Supersedure', 'Umweiselung', 'Supersédure'],
                'missing' => ['Assente', 'Missing', 'Fehlt', 'Absente'],
            ],
            'geneticLine' => [
                'ligustica' => ['Apis mellifera ligustica', 'Apis mellifera ligustica', 'Apis mellifera ligustica', 'Apis mellifera ligustica'],
                'carnica' => ['Apis mellifera carnica', 'Apis mellifera carnica', 'Apis mellifera carnica', 'Apis mellifera carnica'],
                'buckfast' => ['Buckfast', 'Buckfast', 'Buckfast', 'Buckfast'],
                'mellifera' => ['Apis mellifera mellifera', 'Apis mellifera mellifera', 'Apis mellifera mellifera', 'Apis mellifera mellifera'],
                'hybrid' => ['Ibrida', 'Hybrid', 'Hybrid', 'Hybride'],
            ],
            'origin' => [
                'acacia' => ['Acacia', 'Acacia', 'Akazie', 'Acacia'],
                'chestnut' => ['Castagno', 'Chestnut', 'Kastanie', 'Châtaignier'],
                'linden' => ['Tiglio', 'Linden', 'Linde', 'Tilleul'],
                'alpine_rhododendron' => ['Rododendro alpino', 'Alpine rhododendron', 'Alpenrose', 'Rhododendron alpin'],
                'wildflower' => ['Millefiori', 'Wildflower', 'Blütenhonig', 'Toutes fleurs'],
                'citrus' => ['Agrumi', 'Citrus', 'Zitrus', 'Agrumes'],
                'sulla' => ['Sulla', 'Sulla', 'Süßklee', 'Sainfoin d’Espagne'],
                'eucalyptus' => ['Eucalipto', 'Eucalyptus', 'Eukalyptus', 'Eucalyptus'],
                'honeydew' => ['Melata', 'Honeydew', 'Honigtau', 'Miellat'],
            ],
            'treatmentProduct' => [
                'oxalic_acid_sublimated' => ['Acido ossalico sublimato', 'Oxalic acid (sublimated)', 'Oxalsäure (verdampft)', 'Acide oxalique (sublimé)'],
                'oxalic_acid_trickled' => ['Acido ossalico gocciolato', 'Oxalic acid (trickled)', 'Oxalsäure (geträufelt)', 'Acide oxalique (dégoulinant)'],
                'thymol' => ['Timolo', 'Thymol', 'Thymol', 'Thymol'],
                'formic_acid' => ['Acido formico', 'Formic acid', 'Ameisensäure', 'Acide formique'],
                'amitraz' => ['Amitraz', 'Amitraz', 'Amitraz', 'Amitraze'],
                'flumethrin' => ['Flumetrina', 'Flumethrin', 'Flumethrin', 'Flumethrine'],
                'tau_fluvalinate' => ['Tau-fluvalinate (strisce)', 'Tau-fluvalinate (strips)', 'Tau-Fluvalinat (Streifen)', 'Tau-fluvalinate (lanières)'],
                'brood_interruption' => ['Interruzione di covata', 'Brood interruption', 'Brutunterbrechung', 'Interruption du couvain'],
            ],
            'countType' => ['natural' => ['Caduta naturale', 'Natural drop', 'Natürlicher Fall', 'Chute naturelle'], 'post_treatment' => ['Dopo il trattamento', 'Post-treatment', 'Nach der Behandlung', 'Après le traitement']],
            'censusStatus' => ['draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'], 'submitted' => ['Presentato', 'Submitted', 'Eingereicht', 'Déposé']],
            'movementKind' => ['nomadism' => ['Nomadismo', 'Nomadism', 'Wanderung', 'Transhumance'], 'sale' => ['Vendita', 'Sale', 'Verkauf', 'Vente'], 'purchase' => ['Acquisto', 'Purchase', 'Kauf', 'Achat']],
            'movementSubject' => ['hives' => ['Famiglie in arnia', 'Hives', 'Völker', 'Ruches'], 'swarms' => ['Sciami', 'Swarms', 'Schwärme', 'Essaims'], 'nuclei' => ['Nuclei', 'Nuclei', 'Ableger', 'Nucléi'], 'queens' => ['Regine', 'Queens', 'Königinnen', 'Reines']],
            'movementStatus' => ['planned' => ['Pianificata', 'Planned', 'Geplant', 'Planifié'], 'in_transit' => ['In viaggio', 'In transit', 'Unterwegs', 'En transit'], 'completed' => ['Completata', 'Completed', 'Abgeschlossen', 'Terminé'], 'cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulé']],
            'productKind' => [
                'honey' => ['Miele naturale', 'Natural honey', 'Naturhonig', 'Miel naturel'],
                'beeswax' => ['Cera d’api', 'Beeswax', 'Bienenwachs', 'Cire d’abeille'],
                'propolis' => ['Propoli', 'Propolis', 'Propolis', 'Propolis'],
                'royal_jelly' => ['Pappa reale', 'Royal jelly', 'Gelée Royale', 'Gelée royale'],
                'live_bees' => ['Api vive e sciami', 'Live bees and swarms', 'Lebende Bienen und Schwärme', 'Abeilles vivantes et essaims'],
                'non_food' => ['Non alimentare (candele, attrezzatura)', 'Non-food (candles, equipment)', 'Non-Food (Kerzen, Zubehör)', 'Non alimentaire (bougies, matériel)'],
            ],
            'saleChannel' => ['direct' => ['Vendita diretta', 'Direct sale', 'Direktverkauf', 'Vente directe'], 'wholesale' => ['Ingrosso', 'Wholesale', 'Grosshandel', 'Gros']],
            'taxRegime' => [
                'special_agricultural' => ['Regime speciale agricolo (Art. 34)', 'Special agricultural regime (Art. 34)', 'Landwirtschaftliches Sonderregime (Art. 34)', 'Régime agricole spécial (art. 34)'],
                'exemption' => ['Regime di esonero (Art. 34, comma 6)', 'Exemption regime (Art. 34, paragraph 6)', 'Befreiungsregime (Art. 34 Abs. 6)', 'Régime d’exonération (art. 34, al. 6)'],
                'ordinary' => ['Regime ordinario', 'Ordinary regime', 'Ordentliches Regime', 'Régime ordinaire'],
                'standard' => ['Regime svizzero standard', 'Swiss standard regime', 'Schweizer Standardregime', 'Régime suisse standard'],
            ],
            'feedingType' => ['syrup' => ['Sciroppo', 'Syrup', 'Sirup', 'Sirop'], 'candy' => ['Candito', 'Candy', 'Futterteig', 'Candi']],
            'quality' => ['ok' => ['Nella norma', 'Within limits', 'Im Rahmen', 'Dans les limites'], 'warning' => ['Attenzione', 'Warning', 'Warnung', 'Attention'], 'critical' => ['Oltre il limite di legge', 'Above the statutory limit', 'Über dem gesetzlichen Grenzwert', 'Au-dessus de la limite légale'], 'unknown' => ['Non rilevato', 'Not measured', 'Nicht gemessen', 'Non mesuré']],
        ];

        $definitions = [];

        foreach ($groups as $group => $values) {
            foreach ($values as $value => $texts) {
                $definitions["beekeeping.{$group}.{$value}"] = $texts;
            }
        }

        return $definitions;
    }

    /**
     * Codes the API reports: colony alerts, honey warnings, tax notes and the refusals of the business rules.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function codes(): array
    {
        return [
            'beekeeping.alert.swarming_risk' => ['Rischio di sciamatura', 'Swarming risk', 'Schwarmgefahr', 'Risque d’essaimage'],
            'beekeeping.alert.queenless' => ['Orfanità', 'Queenless', 'Weisellos', 'Orphelinage'],
            'beekeeping.alert.weak_colony' => ['Colonia debole', 'Weak colony', 'Schwaches Volk', 'Colonie faible'],
            'beekeeping.alert.old_queen' => ['Regina anziana (3+ anni)', 'Old queen (3+ years)', 'Alte Königin (3+ Jahre)', 'Reine âgée (3+ ans)'],
            'beekeeping.warning.moisture_warning' => ['Umidità sopra il 18%', 'Moisture above 18%', 'Wassergehalt über 18%', 'Humidité supérieure à 18 %'],
            'beekeeping.warning.moisture_critical' => ['Umidità sopra il limite del 20%', 'Moisture above the 20% limit', 'Wassergehalt über dem Grenzwert von 20%', 'Humidité supérieure à la limite de 20 %'],
            'beekeeping.warning.hmf_warning' => ['HMF vicino al limite di 40 mg/kg', 'HMF close to the 40 mg/kg limit', 'HMF nahe am Grenzwert von 40 mg/kg', 'HMF proche de la limite de 40 mg/kg'],
            'beekeeping.warning.hmf_critical' => ['HMF sopra il limite di 40 mg/kg', 'HMF above the 40 mg/kg limit', 'HMF über dem Grenzwert von 40 mg/kg', 'HMF supérieur à la limite de 40 mg/kg'],
            'beekeeping.taxNote.ch_reduced_rate' => ['Aliquota ridotta svizzera', 'Swiss reduced rate', 'Reduzierter Schweizer Satz', 'Taux réduit suisse'],
            'beekeeping.taxNote.ch_standard_rate' => ['Aliquota normale svizzera', 'Swiss standard rate', 'Schweizer Normalsatz', 'Taux normal suisse'],
            'beekeeping.taxNote.it_ordinary_rate' => ['Aliquota IVA ordinaria', 'Ordinary VAT rate', 'Ordentlicher MWST-Satz', 'Taux de TVA ordinaire'],
            'beekeeping.taxNote.it_exempt_art34_c6' => ['Esonero Art. 34, comma 6: nessuna IVA in fattura', 'Art. 34, paragraph 6 exemption: no VAT on the invoice', 'Befreiung nach Art. 34 Abs. 6: keine MWST auf der Rechnung', 'Exonération art. 34, al. 6 : pas de TVA sur la facture'],
            'beekeeping.taxNote.it_exemption_threshold_exceeded' => ['Superata la soglia di esonero: passare al regime speciale', 'Exemption threshold exceeded: switch to the special regime', 'Befreiungsschwelle überschritten: auf das Sonderregime wechseln', 'Seuil d’exonération dépassé : passer au régime spécial'],
            'beekeeping.taxNote.it_special_agricultural' => ['Percentuale di compensazione Art. 34', 'Art. 34 compensation percentage', 'Kompensationssatz nach Art. 34', 'Pourcentage de compensation art. 34'],
            'beekeeping.error.apiary_has_hives' => ['Un apiario con arnie non può essere eliminato.', 'An apiary with hives cannot be deleted.', 'Ein Bienenstand mit Völkern kann nicht gelöscht werden.', 'Un rucher avec des ruches ne peut pas être supprimé.'],
            'beekeeping.error.batch_inspection_incomplete' => ['La visita multipla richiede l’apiario e le arnie.', 'A batch inspection needs the apiary and its hives.', 'Die Sammeldurchsicht braucht den Bienenstand und die Völker.', 'La visite groupée requiert le rucher et ses ruches.'],
            'beekeeping.error.trace_reference_required' => ['La tracciabilità richiede tipo e identificativo.', 'A trace needs a type and an id.', 'Die Rückverfolgung braucht Typ und ID.', 'La traçabilité requiert un type et un identifiant.'],
            'beekeeping.error.tax_regime_invalid' => ['Il regime non esiste in questo Paese.', 'The regime does not exist in this country.', 'Das Regime existiert in diesem Land nicht.', 'Ce régime n’existe pas dans ce pays.'],
            'beekeeping.error.census_submitted' => ['Il censimento di questo anno è già stato presentato.', 'The census of this year was already submitted.', 'Die Erhebung dieses Jahres wurde bereits eingereicht.', 'Le recensement de cette année a déjà été déposé.'],
            'beekeeping.error.intake_not_found' => ['Un ingresso melari non esiste.', 'A super intake does not exist.', 'Ein Honigraum-Eingang existiert nicht.', 'Une entrée de hausses n’existe pas.'],
            'beekeeping.error.intake_already_extracted' => ['Un ingresso melari appartiene già a un lotto.', 'A super intake already belongs to a batch.', 'Ein Honigraum-Eingang gehört bereits zu einem Los.', 'Une entrée de hausses appartient déjà à un lot.'],
            'beekeeping.error.intake_origin_mixed' => ['I melari hanno origini botaniche diverse: scegli l’origine del lotto.', 'The supers have different botanical origins: choose the origin of the batch.', 'Die Honigräume haben verschiedene botanische Herkünfte: Herkunft des Loses wählen.', 'Les hausses ont des origines botaniques différentes : choisissez l’origine du lot.'],
            'beekeeping.error.batch_not_marketable' => ['Il lotto supera un limite di legge e non può essere invasettato come miele.', 'The batch exceeds a statutory limit and cannot be jarred as honey.', 'Das Los überschreitet einen gesetzlichen Grenzwert und darf nicht als Honig abgefüllt werden.', 'Le lot dépasse une limite légale et ne peut pas être mis en pots comme miel.'],
            'beekeeping.error.batch_exhausted' => ['Il lotto non contiene abbastanza miele (residuo: {remaining} kg).', 'The batch does not hold enough honey (remaining: {remaining} kg).', 'Das Los enthält nicht genug Honig (Rest: {remaining} kg).', 'Le lot ne contient pas assez de miel (reste : {remaining} kg).'],
            'beekeeping.error.movement_destination_required' => ['Il nomadismo richiede una destinazione.', 'A nomadism movement needs a destination.', 'Eine Wanderung braucht ein Ziel.', 'Une transhumance requiert une destination.'],
            'beekeeping.error.movement_flora_required' => ['Il nomadismo richiede la fioritura obiettivo.', 'A nomadism movement needs the target flora.', 'Eine Wanderung braucht die Zieltracht.', 'Une transhumance requiert la floraison cible.'],
            'beekeeping.error.movement_counterpart_required' => ['Vendita o acquisto richiedono la controparte.', 'A sale or purchase needs the counterpart.', 'Verkauf oder Kauf braucht die Gegenpartei.', 'Une vente ou un achat requiert la contrepartie.'],
            'beekeeping.error.movement_same_apiary' => ['Origine e destinazione sono lo stesso apiario.', 'Origin and destination are the same apiary.', 'Herkunft und Ziel sind derselbe Bienenstand.', 'L’origine et la destination sont le même rucher.'],
            'beekeeping.error.movement_quantity_exceeds_hives' => ['L’apiario di origine ha solo {available} arnie.', 'The origin apiary only holds {available} hives.', 'Der Herkunftsstand hat nur {available} Völker.', 'Le rucher d’origine ne compte que {available} ruches.'],
            'beekeeping.error.movement_invalid_transition' => ['La movimentazione non può passare a questo stato.', 'The movement cannot change to that status.', 'Die Verschiebung kann nicht in diesen Status wechseln.', 'Le déplacement ne peut pas passer à ce statut.'],
            'beekeeping.error.packaging_product_not_found' => ['Un articolo di confezionamento non esiste.', 'A packaging product does not exist.', 'Ein Verpackungsartikel existiert nicht.', 'Un article d’emballage n’existe pas.'],
            'beekeeping.error.jar_lot_not_found' => ['Lotto di vasetti sconosciuto.', 'Unknown jar lot.', 'Unbekanntes Glaslos.', 'Lot de pots inconnu.'],
            'beekeeping.error.treatment_hives_required' => ['Seleziona le arnie trattate.', 'Select the treated hives.', 'Behandelte Völker auswählen.', 'Sélectionnez les ruches traitées.'],
            'beekeeping.error.treatment_hive_outside_apiary' => ['Un’arnia trattata non appartiene all’apiario.', 'A treated hive does not belong to the apiary.', 'Ein behandeltes Volk gehört nicht zum Bienenstand.', 'Une ruche traitée n’appartient pas au rucher.'],
            'beekeeping.error.supers_blocked_by_withdrawal' => ['Posa melari bloccata: l’arnia {hive} è in sospensione fino al {until} ({product}).', 'Super placement blocked: hive {hive} is in withdrawal until {until} ({product}).', 'Aufsetzen von Honigräumen gesperrt: Volk {hive} ist bis {until} in Wartezeit ({product}).', 'Pose de hausses bloquée : la ruche {hive} est en délai d’attente jusqu’au {until} ({product}).'],
            'beekeeping.error.harvest_blocked_by_withdrawal' => ['Smielatura bloccata: arnie in sospensione fino al {until} ({hives}).', 'Harvest blocked: hives in withdrawal until {until} ({hives}).', 'Ernte gesperrt: Völker in Wartezeit bis {until} ({hives}).', 'Récolte bloquée : ruches en délai d’attente jusqu’au {until} ({hives}).'],
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
