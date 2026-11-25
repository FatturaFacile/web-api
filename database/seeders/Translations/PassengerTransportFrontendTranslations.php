<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the passenger transport module (Mod 176): the dispatch board, bookings with the cross-border VAT split, the
 * service sheet (FDSE, Interbus, welcome sign), the fleet, the tachograph dashboard and the B2B contracts, service exchange and
 * month-end invoicing, plus the codes the API reports (passengerTransport.error.<code>, passengerTransport.compliance.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, NCC, OLR, FDSE, MWST, CQC, CZV, PSVA, DDD) stay untranslated.
 */
final class PassengerTransportFrontendTranslations
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
            self::compliance(),
            self::fields(),
            self::fieldsContracts(),
            self::actions(),
            self::confirmations(),
            self::sections(),
            self::hints(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.PASSENGER_TRANSPORT' => ['Trasporto passeggeri', 'Passenger transport', 'Personentransport', 'Transport de voyageurs'],
            'NAV.PASSENGER_TRANSPORT_DISPATCH' => ['Pianificazione corse', 'Dispatch board', 'Disposition', 'Planification des courses'],
            'NAV.PASSENGER_TRANSPORT_BOOKINGS' => ['Prenotazioni', 'Bookings', 'Buchungen', 'Réservations'],
            'NAV.PASSENGER_TRANSPORT_SHEET' => ['Foglio di servizio', 'Service sheet', 'Dienstblatt', 'Feuille de service'],
            'NAV.PASSENGER_TRANSPORT_FLEET' => ['Flotta', 'Fleet', 'Fahrzeugpark', 'Flotte'],
            'NAV.PASSENGER_TRANSPORT_TACHOGRAPH' => ['Cronotachigrafo', 'Tachograph', 'Fahrtenschreiber', 'Tachygraphe'],
            'NAV.PASSENGER_TRANSPORT_CONTRACTS_BILLING' => ['Contratti e fatturazione', 'Contracts and billing', 'Verträge und Fakturierung', 'Contrats et facturation'],
            'PAGE_TITLE.PASSENGER_TRANSPORT_DISPATCH' => ['Pianificazione delle corse', 'Trip dispatch board', 'Fahrtendisposition', 'Planification des courses'],
            'PAGE_TITLE.PASSENGER_TRANSPORT_BOOKINGS' => ['Prenotazioni di trasporto passeggeri', 'Passenger transport bookings', 'Buchungen im Personentransport', 'Réservations de transport de voyageurs'],
            'PAGE_TITLE.PASSENGER_TRANSPORT_SHEET' => ['Foglio di servizio NCC e lettera di vettura Interbus', 'NCC service sheet and Interbus waybill', 'NCC-Dienstblatt und Interbus-Frachtbrief', 'Feuille de service NCC et lettre de voiture Interbus'],
            'PAGE_TITLE.PASSENGER_TRANSPORT_FLEET' => ['Flotta passeggeri', 'Passenger fleet', 'Personenfahrzeuge', 'Flotte de voyageurs'],
            'PAGE_TITLE.PASSENGER_TRANSPORT_TACHOGRAPH' => ['Cronotachigrafo e documenti degli autisti', 'Tachograph and driver documents', 'Fahrtenschreiber und Fahrerdokumente', 'Tachygraphe et documents des chauffeurs'],
            'PAGE_TITLE.PASSENGER_TRANSPORT_CONTRACTS_BILLING' => ['Contratti B2B, scambio servizi e fatturazione di fine mese', 'B2B contracts, service exchange and month-end billing', 'B2B-Verträge, Leistungsaustausch und Monatsabschluss', 'Contrats B2B, échange de services et facturation de fin de mois'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'passengerTransport.serviceType.airport_transfer' => ['Transfer aeroportuale', 'Airport transfer', 'Flughafentransfer', 'Transfert aéroport'],
            'passengerTransport.serviceType.hourly_disposal' => ['Disposizione oraria', 'Hourly disposal', 'Stundenmiete', 'Mise à disposition à l’heure'],
            'passengerTransport.serviceType.multi_day_tour_coach' => ['Tour in pullman di più giorni', 'Multi-day coach tour', 'Mehrtägige Carreise', 'Voyage en car de plusieurs jours'],
            'passengerTransport.serviceType.school_bus_route' => ['Linea scuolabus', 'School bus route', 'Schulbuslinie', 'Ligne de bus scolaire'],
            'passengerTransport.serviceType.medical_non_emergency' => ['Trasporto sanitario non urgente', 'Non-emergency medical transport', 'Medizinischer Transport ohne Notfall', 'Transport médical non urgent'],

            'passengerTransport.bookingStatus.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'passengerTransport.bookingStatus.confirmed' => ['Confermata', 'Confirmed', 'Bestätigt', 'Confirmée'],
            'passengerTransport.bookingStatus.assigned' => ['Assegnata', 'Assigned', 'Zugewiesen', 'Attribuée'],
            'passengerTransport.bookingStatus.en_route_to_pickup' => ['In viaggio verso il passeggero', 'En route to pickup', 'Unterwegs zum Abholort', 'En route vers la prise en charge'],
            'passengerTransport.bookingStatus.at_pickup_waiting' => ['In attesa al punto di ritiro', 'Waiting at pickup', 'Wartet am Abholort', 'En attente au point de prise en charge'],
            'passengerTransport.bookingStatus.passenger_on_board' => ['Passeggero a bordo', 'Passenger on board', 'Fahrgast an Bord', 'Passager à bord'],
            'passengerTransport.bookingStatus.completed' => ['Completata', 'Completed', 'Abgeschlossen', 'Terminée'],
            'passengerTransport.bookingStatus.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'passengerTransport.bookingStatus.invoiced' => ['Fatturata', 'Invoiced', 'Fakturiert', 'Facturée'],

            'passengerTransport.bookingAction.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'passengerTransport.bookingAction.start_run' => ['Parti verso il cliente', 'Start run', 'Fahrt starten', 'Démarrer la course'],
            'passengerTransport.bookingAction.arrive' => ['Arrivato', 'Arrived', 'Angekommen', 'Arrivé'],
            'passengerTransport.bookingAction.board' => ['Passeggero a bordo', 'Passenger on board', 'Fahrgast an Bord', 'Passager à bord'],
            'passengerTransport.bookingAction.complete' => ['Completa', 'Complete', 'Abschliessen', 'Terminer'],
            'passengerTransport.bookingAction.cancel' => ['Annulla', 'Cancel booking', 'Stornieren', 'Annuler la réservation'],

            'passengerTransport.flightStatus.scheduled' => ['Programmato', 'Scheduled', 'Planmässig', 'Prévu'],
            'passengerTransport.flightStatus.en_route' => ['In volo', 'En route', 'Im Flug', 'En vol'],
            'passengerTransport.flightStatus.delayed' => ['In ritardo', 'Delayed', 'Verspätet', 'Retardé'],
            'passengerTransport.flightStatus.landed' => ['Atterrato', 'Landed', 'Gelandet', 'Atterri'],
            'passengerTransport.flightStatus.cancelled' => ['Cancellato', 'Cancelled', 'Annulliert', 'Annulé'],

            'passengerTransport.vehicleCategory.sedan_ncc' => ['Berlina NCC', 'NCC sedan', 'NCC-Limousine', 'Berline NCC'],
            'passengerTransport.vehicleCategory.luxury_van_ncc' => ['Van di lusso NCC', 'NCC luxury van', 'NCC-Luxusvan', 'Van de luxe NCC'],
            'passengerTransport.vehicleCategory.minibus_9_16' => ['Minibus 9-16 posti', 'Minibus 9-16 seats', 'Minibus 9–16 Plätze', 'Minibus 9-16 places'],
            'passengerTransport.vehicleCategory.gt_coach_over_3_5t' => ['Pullman GT oltre 3,5 t', 'GT coach over 3.5 t', 'Reisecar über 3,5 t', 'Autocar GT de plus de 3,5 t'],
            'passengerTransport.vehicleCategory.school_bus' => ['Scuolabus', 'School bus', 'Schulbus', 'Bus scolaire'],
            'passengerTransport.vehicleCategory.taxi' => ['Taxi', 'Taxi', 'Taxi', 'Taxi'],

            'passengerTransport.borderCrossing.chiasso_brogeda' => ['Chiasso-Brogeda', 'Chiasso-Brogeda', 'Chiasso-Brogeda', 'Chiasso-Brogeda'],
            'passengerTransport.borderCrossing.gaggiolo' => ['Gaggiolo', 'Gaggiolo', 'Gaggiolo', 'Gaggiolo'],
            'passengerTransport.borderCrossing.ponte_tresa' => ['Ponte Tresa', 'Ponte Tresa', 'Ponte Tresa', 'Ponte Tresa'],
            'passengerTransport.borderCrossing.porto_ceresio' => ['Porto Ceresio', 'Porto Ceresio', 'Porto Ceresio', 'Porto Ceresio'],
            'passengerTransport.borderCrossing.sempione' => ['Sempione', 'Simplon', 'Simplon', 'Simplon'],
            'passengerTransport.borderCrossing.gran_san_bernardo' => ['Gran San Bernardo', 'Great St Bernard', 'Grosser Sankt Bernhard', 'Grand-Saint-Bernard'],
            'passengerTransport.borderCrossing.castasegna' => ['Castasegna', 'Castasegna', 'Castasegna', 'Castasegna'],
            'passengerTransport.borderCrossing.tirano' => ['Tirano', 'Tirano', 'Tirano', 'Tirano'],
            'passengerTransport.borderCrossing.none' => ['Nessun valico', 'No border crossing', 'Kein Grenzübergang', 'Aucun passage de frontière'],

            'passengerTransport.drivingRegime.olr1_ec561' => ['OLR 1 / Reg. (CE) 561/2006', 'OLR 1 / Reg. (EC) 561/2006', 'ARV 1 / VO (EG) 561/2006', 'OLR 1 / Règl. (CE) 561/2006'],
            'passengerTransport.drivingRegime.olr2' => ['OLR 2', 'OLR 2', 'ARV 2', 'OLR 2'],

            'passengerTransport.sheetStatus.open' => ['Aperto', 'Open', 'Offen', 'Ouverte'],
            'passengerTransport.sheetStatus.closed' => ['Chiuso e firmato', 'Closed and signed', 'Abgeschlossen und signiert', 'Clôturée et signée'],
            'passengerTransport.sheetStatus.transmitted' => ['Trasmesso al MIT', 'Transmitted to the MIT', 'An das MIT übermittelt', 'Transmise au MIT'],

            'passengerTransport.exchangeDirection.none' => ['Nessuno scambio', 'No exchange', 'Kein Austausch', 'Aucun échange'],
            'passengerTransport.exchangeDirection.ceded' => ['Ceduto', 'Ceded', 'Abgetreten', 'Cédé'],
            'passengerTransport.exchangeDirection.acquired' => ['Acquisito', 'Acquired', 'Übernommen', 'Repris'],

            'passengerTransport.exchangeAction.cede' => ['Cedi a un collega', 'Cede to a colleague', 'An Kollegen abtreten', 'Céder à un confrère'],
            'passengerTransport.exchangeAction.acquire' => ['Acquisisci da un collega', 'Acquire from a colleague', 'Von Kollegen übernehmen', 'Reprendre d’un confrère'],
            'passengerTransport.exchangeAction.release' => ['Annulla lo scambio', 'Undo the exchange', 'Austausch aufheben', 'Annuler l’échange'],

            'passengerTransport.vatLineKind.it_domestic' => ['Tratta in Italia', 'Leg in Italy', 'Strecke in Italien', 'Trajet en Italie'],
            'passengerTransport.vatLineKind.it_exempt_art10_n14' => ['Trasporto urbano esente', 'Exempt urban transport', 'Befreiter Stadtverkehr', 'Transport urbain exonéré'],
            'passengerTransport.vatLineKind.it_foreign_leg' => ['Tratta estera, fuori campo IVA', 'Foreign leg, outside the scope of VAT', 'Auslandstrecke, nicht steuerbar', 'Trajet étranger, hors champ de la TVA'],
            'passengerTransport.vatLineKind.ch_domestic' => ['Tratta in Svizzera', 'Leg in Switzerland', 'Strecke in der Schweiz', 'Trajet en Suisse'],
            'passengerTransport.vatLineKind.ch_foreign_leg' => ['Tratta estera, fuori campo MWST', 'Foreign leg, outside the scope of MWST', 'Auslandstrecke, nicht steuerbar', 'Trajet étranger, hors champ de la TVA'],
            'passengerTransport.vatLineKind.ch_exempt_art23' => ['Trasporto internazionale esente', 'Exempt international transport', 'Befreiter internationaler Transport', 'Transport international exonéré'],

            'passengerTransport.infringement.daily_driving_exceeded' => ['Guida giornaliera oltre il limite', 'Daily driving over the limit', 'Tageslenkzeit überschritten', 'Conduite journalière au-delà de la limite'],
            'passengerTransport.infringement.continuous_driving_exceeded' => ['Guida continuata oltre 4 h 30', 'Continuous driving over 4 h 30', 'Ununterbrochene Lenkzeit über 4 h 30', 'Conduite continue au-delà de 4 h 30'],
            'passengerTransport.infringement.weekly_driving_exceeded' => ['Guida settimanale oltre il limite', 'Weekly driving over the limit', 'Wochenlenkzeit überschritten', 'Conduite hebdomadaire au-delà de la limite'],

            'passengerTransport.expiry.missing' => ['Non registrato', 'Not recorded', 'Nicht erfasst', 'Non enregistré'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'passengerTransport.error.generic' => ['Operazione non riuscita.', 'The operation failed.', 'Der Vorgang ist fehlgeschlagen.', 'L’opération a échoué.'],
            'passengerTransport.error.booking_locked' => ['La prenotazione non è più modificabile.', 'The booking can no longer be changed.', 'Die Buchung kann nicht mehr geändert werden.', 'La réservation ne peut plus être modifiée.'],
            'passengerTransport.error.booking_action_unknown' => ['Azione sconosciuta: {action}.', 'Unknown action: {action}.', 'Unbekannte Aktion: {action}.', 'Action inconnue : {action}.'],
            'passengerTransport.error.booking_transition_refused' => ['L’azione {action} non è possibile dallo stato {status}.', 'Action {action} is not possible from status {status}.', 'Die Aktion {action} ist im Status {status} nicht möglich.', 'L’action {action} n’est pas possible depuis le statut {status}.'],
            'passengerTransport.error.booking_delete_draft_only' => ['Si possono eliminare solo le prenotazioni in bozza.', 'Only draft bookings can be deleted.', 'Nur Entwürfe können gelöscht werden.', 'Seules les réservations en brouillon peuvent être supprimées.'],
            'passengerTransport.error.assignment_blocked' => ['Assegnazione bloccata da violazioni normative: vedi i dettagli.', 'Assignment blocked by legal violations: see the details.', 'Zuweisung durch Rechtsverstösse blockiert: siehe Details.', 'Attribution bloquée par des infractions légales : voir les détails.'],
            'passengerTransport.error.assignment_warnings_unacknowledged' => ['Ci sono avvisi da confermare prima di assegnare.', 'There are warnings to acknowledge before assigning.', 'Vor der Zuweisung müssen Hinweise bestätigt werden.', 'Des avertissements doivent être confirmés avant l’attribution.'],
            'passengerTransport.error.driver_without_compliance_record' => ['L’autista non ha una scheda di conformità (patente, CQC, CZV, cronotachigrafo).', 'The driver has no compliance record (licence, CQC, CZV, tachograph).', 'Der Fahrer hat keine Compliance-Karte (Führerschein, CQC, CZV, Fahrtenschreiber).', 'Le chauffeur n’a pas de fiche de conformité (permis, CQC, CZV, tachygraphe).'],
            'passengerTransport.error.ddd_file_invalid' => ['Il file del cronotachigrafo è vuoto o troncato.', 'The tachograph file is empty or truncated.', 'Die Fahrtenschreiberdatei ist leer oder abgeschnitten.', 'Le fichier du tachygraphe est vide ou tronqué.'],
            'passengerTransport.error.ddd_file_unrecognised' => ['Formato del file del cronotachigrafo non riconosciuto.', 'Tachograph file format not recognised.', 'Format der Fahrtenschreiberdatei nicht erkannt.', 'Format du fichier du tachygraphe non reconnu.'],
            'passengerTransport.error.ddd_duplicate' => ['Questo file è già in archivio (stesso SHA-256).', 'This file is already archived (same SHA-256).', 'Diese Datei ist bereits archiviert (gleicher SHA-256).', 'Ce fichier est déjà archivé (même SHA-256).'],
            'passengerTransport.error.ddd_driver_unresolved' => ['Nessun autista corrisponde alla carta del file: scegli l’autista.', 'No driver matches the card of the file: choose the driver.', 'Kein Fahrer passt zur Karte der Datei: Fahrer auswählen.', 'Aucun chauffeur ne correspond à la carte du fichier : choisissez le chauffeur.'],
            'passengerTransport.error.ddd_card_mismatch' => ['La carta {card} del file non corrisponde a quella dell’autista.', 'Card {card} of the file does not match the card of the driver.', 'Die Karte {card} der Datei passt nicht zur Karte des Fahrers.', 'La carte {card} du fichier ne correspond pas à celle du chauffeur.'],
            'passengerTransport.error.booking_without_flight' => ['La prenotazione non ha volo, aeroporto e arrivo previsto.', 'The booking has no flight, airport and scheduled arrival.', 'Die Buchung hat keinen Flug, Flughafen und keine planmässige Ankunft.', 'La réservation n’a ni vol, ni aéroport, ni arrivée prévue.'],
            'passengerTransport.error.sheet_requires_assignment' => ['Assegna autista e veicolo prima di aprire il foglio di servizio.', 'Assign a driver and a vehicle before opening the service sheet.', 'Weisen Sie Fahrer und Fahrzeug zu, bevor Sie das Dienstblatt öffnen.', 'Attribuez un chauffeur et un véhicule avant d’ouvrir la feuille de service.'],
            'passengerTransport.error.sheet_booking_status' => ['Non si può aprire un foglio da una prenotazione in stato {status}.', 'A sheet cannot be opened from a booking in status {status}.', 'Aus einer Buchung im Status {status} kann kein Blatt eröffnet werden.', 'Une feuille ne peut pas être ouverte depuis une réservation au statut {status}.'],
            'passengerTransport.error.sheet_already_open' => ['Esiste già un foglio aperto per questa prenotazione.', 'An open sheet already exists for this booking.', 'Für diese Buchung existiert bereits ein offenes Blatt.', 'Une feuille ouverte existe déjà pour cette réservation.'],
            'passengerTransport.error.sheet_locked' => ['Il foglio è chiuso e non è più modificabile.', 'The sheet is closed and can no longer be changed.', 'Das Blatt ist abgeschlossen und kann nicht mehr geändert werden.', 'La feuille est clôturée et ne peut plus être modifiée.'],
            'passengerTransport.error.sheet_incomplete' => ['Campi obbligatori mancanti: {fields}.', 'Mandatory fields missing: {fields}.', 'Pflichtfelder fehlen: {fields}.', 'Champs obligatoires manquants : {fields}.'],
            'passengerTransport.error.sheet_not_closed' => ['Chiudi il foglio prima di trasmetterlo.', 'Close the sheet before transmitting it.', 'Schliessen Sie das Blatt vor der Übermittlung.', 'Clôturez la feuille avant de la transmettre.'],
            'passengerTransport.error.sheet_not_international_coach' => ['Il foglio non è una lettera di vettura Interbus (non è un servizio internazionale in pullman).', 'The sheet is not an Interbus waybill (not an international coach service).', 'Das Blatt ist kein Interbus-Frachtbrief (kein internationaler Carverkehr).', 'La feuille n’est pas une lettre de voiture Interbus (pas un service international en car).'],
            'passengerTransport.error.sheet_kilometres_decreasing' => ['I chilometri non possono diminuire da partenza a inizio, fine e rientro.', 'Kilometres must not decrease from departure to start, end and return.', 'Die Kilometer dürfen von Abfahrt über Start und Ende bis Rückkehr nicht sinken.', 'Les kilomètres ne peuvent pas diminuer du départ au début, à la fin et au retour.'],
            'passengerTransport.error.invoice_requires_customer' => ['La prenotazione non ha un cliente da fatturare.', 'The booking has no customer to invoice.', 'Die Buchung hat keinen Kunden zum Fakturieren.', 'La réservation n’a pas de client à facturer.'],
            'passengerTransport.error.invoice_booking_not_completed' => ['La prenotazione {booking} non è completata.', 'Booking {booking} is not completed.', 'Die Buchung {booking} ist nicht abgeschlossen.', 'La réservation {booking} n’est pas terminée.'],
            'passengerTransport.error.invoice_booking_already_invoiced' => ['La prenotazione {booking} è già fatturata.', 'Booking {booking} is already invoiced.', 'Die Buchung {booking} ist bereits fakturiert.', 'La réservation {booking} est déjà facturée.'],
            'passengerTransport.error.invoice_mixed_currencies' => ['Le prenotazioni hanno valute diverse: fatturale separatamente.', 'The bookings have different currencies: invoice them separately.', 'Die Buchungen haben verschiedene Währungen: separat fakturieren.', 'Les réservations ont des devises différentes : facturez-les séparément.'],
            'passengerTransport.error.billing_period_invalid' => ['Periodo non valido: usa AAAA-MM.', 'Invalid period: use YYYY-MM.', 'Ungültiger Zeitraum: JJJJ-MM verwenden.', 'Période invalide : utilisez AAAA-MM.'],
            'passengerTransport.error.subcontract_payout_invalid' => ['Il compenso al collega deve essere tra 0 e la tariffa ({agreed}).', 'The colleague payout must be between 0 and the fare ({agreed}).', 'Die Vergütung an den Kollegen muss zwischen 0 und dem Preis ({agreed}) liegen.', 'La rémunération du confrère doit être comprise entre 0 et le tarif ({agreed}).'],
            'passengerTransport.error.subcontract_commission_invalid' => ['La commissione deve essere tra 0 e la tariffa ({agreed}).', 'The commission must be between 0 and the fare ({agreed}).', 'Die Kommission muss zwischen 0 und dem Preis ({agreed}) liegen.', 'La commission doit être comprise entre 0 et le tarif ({agreed}).'],
            'passengerTransport.error.subcontract_carrier_required' => ['Scegli il vettore partner.', 'Choose the partner carrier.', 'Wählen Sie den Partner-Transporteur.', 'Choisissez le transporteur partenaire.'],
            'passengerTransport.error.subcontract_action_unknown' => ['Azione di scambio sconosciuta: {action}.', 'Unknown exchange action: {action}.', 'Unbekannte Austauschaktion: {action}.', 'Action d’échange inconnue : {action}.'],
            'passengerTransport.error.contract_not_valid_on_date' => ['Il contratto non è valido il {date}.', 'The contract is not valid on {date}.', 'Der Vertrag ist am {date} nicht gültig.', 'Le contrat n’est pas valable le {date}.'],
            'passengerTransport.error.contract_route_unknown' => ['La tratta {route} non è nel listino del contratto.', 'Route {route} is not in the rate card of the contract.', 'Die Strecke {route} steht nicht in der Preisliste des Vertrags.', 'Le trajet {route} ne figure pas dans la grille tarifaire du contrat.'],
            'passengerTransport.error.vehicle_has_open_trips' => ['Il veicolo ha corse aperte.', 'The vehicle has open trips.', 'Das Fahrzeug hat offene Fahrten.', 'Le véhicule a des courses ouvertes.'],
            'passengerTransport.error.driver_record_exists' => ['L’autista ha già una scheda di conformità.', 'The driver already has a compliance record.', 'Der Fahrer hat bereits eine Compliance-Karte.', 'Le chauffeur a déjà une fiche de conformité.'],
            'passengerTransport.error.contract_has_bookings' => ['Il contratto ha prenotazioni e non si può eliminare.', 'The contract has bookings and cannot be deleted.', 'Der Vertrag hat Buchungen und kann nicht gelöscht werden.', 'Le contrat a des réservations et ne peut pas être supprimé.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function compliance(): array
    {
        return [
            'passengerTransport.compliance.vehicle_inactive' => ['Il veicolo non è attivo.', 'The vehicle is not active.', 'Das Fahrzeug ist nicht aktiv.', 'Le véhicule n’est pas actif.'],
            'passengerTransport.compliance.vehicle_capacity_exceeded' => ['I passeggeri ({passengers}) superano i posti del veicolo ({capacity}).', 'Passengers ({passengers}) exceed the vehicle seats ({capacity}).', 'Die Fahrgäste ({passengers}) übersteigen die Sitzplätze des Fahrzeugs ({capacity}).', 'Les passagers ({passengers}) dépassent les places du véhicule ({capacity}).'],
            'passengerTransport.compliance.vehicle_overlap' => ['Il veicolo è già impegnato nella corsa {booking}.', 'The vehicle is already committed to trip {booking}.', 'Das Fahrzeug ist bereits für die Fahrt {booking} eingeplant.', 'Le véhicule est déjà engagé sur la course {booking}.'],
            'passengerTransport.compliance.driver_overlap' => ['L’autista è già impegnato nella corsa {booking}.', 'The driver is already committed to trip {booking}.', 'Der Fahrer ist bereits für die Fahrt {booking} eingeplant.', 'Le chauffeur est déjà engagé sur la course {booking}.'],
            'passengerTransport.compliance.license_missing' => ['Patente non registrata.', 'Driving licence not recorded.', 'Führerschein nicht erfasst.', 'Permis de conduire non enregistré.'],
            'passengerTransport.compliance.license_expired' => ['Patente scaduta il {expired_on} (corsa del {trip_date}).', 'Driving licence expired on {expired_on} (trip on {trip_date}).', 'Führerschein abgelaufen am {expired_on} (Fahrt am {trip_date}).', 'Permis expiré le {expired_on} (course du {trip_date}).'],
            'passengerTransport.compliance.license_expiring' => ['La patente scade il {expires_on}.', 'Driving licence expires on {expires_on}.', 'Der Führerschein läuft am {expires_on} ab.', 'Le permis expire le {expires_on}.'],
            'passengerTransport.compliance.license_category_missing' => ['La patente non copre la categoria {required}.', 'The licence does not cover category {required}.', 'Der Führerschein deckt die Kategorie {required} nicht ab.', 'Le permis ne couvre pas la catégorie {required}.'],
            'passengerTransport.compliance.cqc_missing' => ['CQC Persone non registrata.', 'CQC Persone not recorded.', 'CQC Persone nicht erfasst.', 'CQC Persone non enregistrée.'],
            'passengerTransport.compliance.cqc_expired' => ['CQC Persone scaduta il {expired_on} (corsa del {trip_date}).', 'CQC Persone expired on {expired_on} (trip on {trip_date}).', 'CQC Persone abgelaufen am {expired_on} (Fahrt am {trip_date}).', 'CQC Persone expirée le {expired_on} (course du {trip_date}).'],
            'passengerTransport.compliance.cqc_expiring' => ['La CQC Persone scade il {expires_on}.', 'CQC Persone expires on {expires_on}.', 'Die CQC Persone läuft am {expires_on} ab.', 'La CQC Persone expire le {expires_on}.'],
            'passengerTransport.compliance.czv_missing' => ['CZV non registrato.', 'CZV not recorded.', 'CZV nicht erfasst.', 'CZV non enregistré.'],
            'passengerTransport.compliance.czv_expired' => ['CZV scaduto il {expired_on} (corsa del {trip_date}).', 'CZV expired on {expired_on} (trip on {trip_date}).', 'CZV abgelaufen am {expired_on} (Fahrt am {trip_date}).', 'CZV expiré le {expired_on} (course du {trip_date}).'],
            'passengerTransport.compliance.czv_expiring' => ['Il CZV scade il {expires_on}.', 'CZV expires on {expires_on}.', 'Das CZV läuft am {expires_on} ab.', 'Le CZV expire le {expires_on}.'],
            'passengerTransport.compliance.tachograph_card_missing' => ['Carta del cronotachigrafo non registrata.', 'Tachograph card not recorded.', 'Fahrerkarte nicht erfasst.', 'Carte de tachygraphe non enregistrée.'],
            'passengerTransport.compliance.tachograph_card_expired' => ['Carta del cronotachigrafo scaduta il {expired_on} (corsa del {trip_date}).', 'Tachograph card expired on {expired_on} (trip on {trip_date}).', 'Fahrerkarte abgelaufen am {expired_on} (Fahrt am {trip_date}).', 'Carte de tachygraphe expirée le {expired_on} (course du {trip_date}).'],
            'passengerTransport.compliance.tachograph_card_expiring' => ['La carta del cronotachigrafo scade il {expires_on}.', 'Tachograph card expires on {expires_on}.', 'Die Fahrerkarte läuft am {expires_on} ab.', 'La carte de tachygraphe expire le {expires_on}.'],
            'passengerTransport.compliance.continuous_driving_exceeded' => ['Guida continuata di {continuous_minutes} min: servono {breaks_needed} pause da 45 min ({break_minutes_planned} min previsti).', 'Continuous driving of {continuous_minutes} min: {breaks_needed} breaks of 45 min needed ({break_minutes_planned} min planned).', 'Ununterbrochene Lenkzeit von {continuous_minutes} Min.: {breaks_needed} Pausen von 45 Min. nötig ({break_minutes_planned} Min. geplant).', 'Conduite continue de {continuous_minutes} min : {breaks_needed} pauses de 45 min nécessaires ({break_minutes_planned} min prévues).'],
            'passengerTransport.compliance.continuous_driving_close' => ['Guida continuata di {continuous_minutes} min, vicina al limite di 4 h 30.', 'Continuous driving of {continuous_minutes} min, close to the 4 h 30 limit.', 'Ununterbrochene Lenkzeit von {continuous_minutes} Min., nahe an der Grenze von 4 h 30.', 'Conduite continue de {continuous_minutes} min, proche de la limite de 4 h 30.'],
            'passengerTransport.compliance.daily_driving_exceeded' => ['La guida giornaliera di {daily_minutes} min supera il limite di {limit_minutes} min.', 'Daily driving of {daily_minutes} min exceeds the limit of {limit_minutes} min.', 'Die Tageslenkzeit von {daily_minutes} Min. überschreitet die Grenze von {limit_minutes} Min.', 'La conduite journalière de {daily_minutes} min dépasse la limite de {limit_minutes} min.'],
            'passengerTransport.compliance.daily_driving_extension_exhausted' => ['Oltre 9 h di guida ma le due estensioni a 10 h della settimana sono esaurite ({extended_days_used} usate).', 'Over 9 h of driving but the two 10 h extensions of the week are used up ({extended_days_used} used).', 'Über 9 h Lenkzeit, aber die zwei Verlängerungen auf 10 h der Woche sind aufgebraucht ({extended_days_used} genutzt).', 'Plus de 9 h de conduite mais les deux prolongations à 10 h de la semaine sont épuisées ({extended_days_used} utilisées).'],
            'passengerTransport.compliance.daily_driving_extension_used' => ['Guida di {daily_minutes} min: usa un’estensione a 10 h ({extended_days_used} su 2 in questa settimana).', 'Driving of {daily_minutes} min: uses a 10 h extension ({extended_days_used} of 2 this week).', 'Lenkzeit von {daily_minutes} Min.: nutzt eine Verlängerung auf 10 h ({extended_days_used} von 2 in dieser Woche).', 'Conduite de {daily_minutes} min : utilise une prolongation à 10 h ({extended_days_used} sur 2 cette semaine).'],
            'passengerTransport.compliance.weekly_driving_exceeded' => ['La guida settimanale di {weekly_minutes} min supera il limite di {limit_minutes} min (56 h).', 'Weekly driving of {weekly_minutes} min exceeds the limit of {limit_minutes} min (56 h).', 'Die Wochenlenkzeit von {weekly_minutes} Min. überschreitet die Grenze von {limit_minutes} Min. (56 h).', 'La conduite hebdomadaire de {weekly_minutes} min dépasse la limite de {limit_minutes} min (56 h).'],
            'passengerTransport.compliance.weekly_driving_close' => ['Guida settimanale di {weekly_minutes} min, vicina al limite di 56 h.', 'Weekly driving of {weekly_minutes} min, close to the 56 h limit.', 'Wochenlenkzeit von {weekly_minutes} Min., nahe an der Grenze von 56 h.', 'Conduite hebdomadaire de {weekly_minutes} min, proche de la limite de 56 h.'],
            'passengerTransport.compliance.biweekly_driving_exceeded' => ['La guida su due settimane di {biweekly_minutes} min supera il limite di {limit_minutes} min (90 h).', 'Two-week driving of {biweekly_minutes} min exceeds the limit of {limit_minutes} min (90 h).', 'Die Lenkzeit über zwei Wochen von {biweekly_minutes} Min. überschreitet die Grenze von {limit_minutes} Min. (90 h).', 'La conduite sur deux semaines de {biweekly_minutes} min dépasse la limite de {limit_minutes} min (90 h).'],
            'passengerTransport.compliance.daily_rest_insufficient' => ['Il riposo giornaliero di {rest_minutes} min è sotto il minimo di {minimum_minutes} min.', 'Daily rest of {rest_minutes} min is below the minimum of {minimum_minutes} min.', 'Die tägliche Ruhezeit von {rest_minutes} Min. liegt unter dem Minimum von {minimum_minutes} Min.', 'Le repos journalier de {rest_minutes} min est inférieur au minimum de {minimum_minutes} min.'],
            'passengerTransport.compliance.reduced_rests_exhausted' => ['Le tre riduzioni del riposo giornaliero a 9 h tra due riposi settimanali sono esaurite ({reduced_rests_used} usate).', 'The three reductions of the daily rest to 9 h between weekly rests are used up ({reduced_rests_used} used).', 'Die drei Verkürzungen der täglichen Ruhezeit auf 9 h zwischen Wochenruhezeiten sind aufgebraucht ({reduced_rests_used} genutzt).', 'Les trois réductions du repos journalier à 9 h entre deux repos hebdomadaires sont épuisées ({reduced_rests_used} utilisées).'],
            'passengerTransport.compliance.reduced_rest_used' => ['Riposo ridotto a 9 h: riduzione n. {reduced_rests_used} su 3.', 'Reduced 9 h rest: reduction no. {reduced_rests_used} of 3.', 'Verkürzte Ruhezeit von 9 h: Verkürzung Nr. {reduced_rests_used} von 3.', 'Repos réduit à 9 h : réduction n° {reduced_rests_used} sur 3.'],
            'passengerTransport.compliance.weekly_rest_overdue' => ['{hours_since_weekly_rest} h dall’ultimo riposo settimanale: il limite è 144 h.', '{hours_since_weekly_rest} h since the last weekly rest: the limit is 144 h.', '{hours_since_weekly_rest} h seit der letzten Wochenruhezeit: die Grenze liegt bei 144 h.', '{hours_since_weekly_rest} h depuis le dernier repos hebdomadaire : la limite est de 144 h.'],
            'passengerTransport.compliance.olr2_break_required' => ['Guida continuata di {continuous_minutes} min: è richiesta una pausa di {break_minutes_required} min ({break_minutes_planned} min previsti).', 'Continuous driving of {continuous_minutes} min: a break of {break_minutes_required} min is required ({break_minutes_planned} min planned).', 'Ununterbrochene Lenkzeit von {continuous_minutes} Min.: eine Pause von {break_minutes_required} Min. ist erforderlich ({break_minutes_planned} Min. geplant).', 'Conduite continue de {continuous_minutes} min : une pause de {break_minutes_required} min est requise ({break_minutes_planned} min prévues).'],
            'passengerTransport.compliance.multi_day_first_day_only' => ['Viaggio di più giorni: i limiti sono verificati sul primo giorno.', 'Multi-day trip: the limits are checked on the first day.', 'Mehrtägige Reise: die Grenzen werden am ersten Tag geprüft.', 'Voyage de plusieurs jours : les limites sont vérifiées le premier jour.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'passengerTransport.field.accessibility' => ['Esigenze di accessibilità', 'Accessibility needs', 'Barrierefreiheit', 'Besoins d’accessibilité'],
            'passengerTransport.field.actions' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'passengerTransport.field.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'passengerTransport.field.inactive' => ['Non attivo', 'Inactive', 'Inaktiv', 'Inactif'],
            'passengerTransport.field.agreedPrice' => ['Tariffa netta concordata', 'Agreed net fare', 'Vereinbarter Nettopreis', 'Tarif net convenu'],
            'passengerTransport.field.arrivalAirport' => ['Aeroporto di arrivo', 'Arrival airport', 'Ankunftsflughafen', 'Aéroport d’arrivée'],
            'passengerTransport.field.art23Exemption' => ['Esenzione Art. 23 MWSTG (trasporto internazionale)', 'Art. 23 MWSTG exemption (international transport)', 'Befreiung nach Art. 23 MWSTG (internationaler Transport)', 'Exonération selon l’art. 23 LTVA (transport international)'],
            'passengerTransport.field.birthDate' => ['Data di nascita', 'Date of birth', 'Geburtsdatum', 'Date de naissance'],
            'passengerTransport.field.bookingCode' => ['Prenotazione', 'Booking', 'Buchung', 'Réservation'],
            'passengerTransport.field.boosterSeats' => ['Rialzi', 'Booster seats', 'Sitzerhöhungen', 'Rehausseurs'],
            'passengerTransport.field.borderCrossing' => ['Valico di frontiera', 'Border crossing', 'Grenzübergang', 'Passage de frontière'],
            'passengerTransport.field.capacity' => ['Posti', 'Seats', 'Plätze', 'Places'],
            'passengerTransport.field.carrier' => ['Vettore partner', 'Partner carrier', 'Partner-Transporteur', 'Transporteur partenaire'],
            'passengerTransport.field.carrierIata' => ['Compagnia aerea (IATA)', 'Airline (IATA)', 'Fluggesellschaft (IATA)', 'Compagnie aérienne (IATA)'],
            'passengerTransport.field.childSeats' => ['Seggiolini bambino', 'Child seats', 'Kindersitze', 'Sièges enfant'],
            'passengerTransport.field.chKm' => ['km in Svizzera', 'km in Switzerland', 'km in der Schweiz', 'km en Suisse'],
            'passengerTransport.field.code' => ['Codice', 'Code', 'Code', 'Code'],
            'passengerTransport.field.commission' => ['Commissione', 'Commission', 'Kommission', 'Commission'],
            'passengerTransport.field.commissionKept' => ['Commissione trattenuta', 'Commission kept', 'Behaltene Kommission', 'Commission retenue'],
            'passengerTransport.field.commissionOwed' => ['Commissione dovuta', 'Commission owed', 'Geschuldete Kommission', 'Commission due'],
            'passengerTransport.field.commissionRate' => ['Commissione concierge (%)', 'Concierge commission (%)', 'Concierge-Kommission (%)', 'Commission du concierge (%)'],
            'passengerTransport.field.conciergeCommission' => ['Commissione concierge', 'Concierge commission', 'Concierge-Kommission', 'Commission du concierge'],
            'passengerTransport.field.contract' => ['Contratto', 'Contract', 'Vertrag', 'Contrat'],
            'passengerTransport.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'passengerTransport.field.customer' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'passengerTransport.field.date' => ['Data', 'Date', 'Datum', 'Date'],
            'passengerTransport.field.day' => ['Giorno', 'Day', 'Tag', 'Jour'],
            'passengerTransport.field.destination' => ['Destinazione', 'Destination', 'Ziel', 'Destination'],
            'passengerTransport.field.digitalTachograph' => ['Cronotachigrafo digitale', 'Digital tachograph', 'Digitaler Fahrtenschreiber', 'Tachygraphe numérique'],
            'passengerTransport.field.document' => ['Documento', 'Document', 'Dokument', 'Document'],
            'passengerTransport.field.driver' => ['Autista', 'Driver', 'Fahrer', 'Chauffeur'],
            'passengerTransport.field.dropoff' => ['Destinazione', 'Drop-off', 'Ziel', 'Destination'],
            'passengerTransport.field.dropoffAddress' => ['Indirizzo di destinazione', 'Drop-off address', 'Zieladresse', 'Adresse de destination'],
            'passengerTransport.field.dropoffCountry' => ['Paese di destinazione', 'Drop-off country', 'Zielland', 'Pays de destination'],
            'passengerTransport.field.dropoffLat' => ['Latitudine destinazione', 'Drop-off latitude', 'Breitengrad Ziel', 'Latitude de destination'],
            'passengerTransport.field.dropoffLng' => ['Longitudine destinazione', 'Drop-off longitude', 'Längengrad Ziel', 'Longitude de destination'],
            'passengerTransport.field.employee' => ['Dipendente', 'Employee', 'Mitarbeiter', 'Employé'],
            'passengerTransport.field.equipment' => ['Dotazioni', 'Equipment', 'Ausstattung', 'Équipement'],
            'passengerTransport.field.exchangeAction' => ['Scambio', 'Exchange', 'Austausch', 'Échange'],
            'passengerTransport.field.fdseProtocol' => ['Protocollo FDSE del MIT', 'MIT FDSE protocol', 'FDSE-Protokoll des MIT', 'Protocole FDSE du MIT'],
            'passengerTransport.field.file' => ['File', 'File', 'Datei', 'Fichier'],
            'passengerTransport.field.flight' => ['Volo', 'Flight', 'Flug', 'Vol'],
            'passengerTransport.field.flightNumber' => ['Numero di volo o treno', 'Flight or train number', 'Flug- oder Zugnummer', 'Numéro de vol ou de train'],
            'passengerTransport.field.garageDeparture' => ['Partenza dalla rimessa', 'Garage departure', 'Abfahrt ab Garage', 'Départ du garage'],
            'passengerTransport.field.garageDepartureKm' => ['km alla partenza dalla rimessa', 'km at garage departure', 'km bei Abfahrt ab Garage', 'km au départ du garage'],
            'passengerTransport.field.garageReturn' => ['Rientro in rimessa', 'Garage return', 'Rückkehr in die Garage', 'Retour au garage'],
            'passengerTransport.field.garageReturnKm' => ['km al rientro in rimessa', 'km at garage return', 'km bei Rückkehr in die Garage', 'km au retour au garage'],
            'passengerTransport.field.grossWeight' => ['Peso complessivo (t)', 'Gross weight (t)', 'Gesamtgewicht (t)', 'Poids total (t)'],
            'passengerTransport.field.infantSeats' => ['Navicelle e culle', 'Infant carriers', 'Babyschalen', 'Nacelles'],
            'passengerTransport.field.infringement' => ['Infrazione', 'Infringement', 'Verstoss', 'Infraction'],
            'passengerTransport.field.infringements' => ['Infrazioni', 'Infringements', 'Verstösse', 'Infractions'],
            'passengerTransport.field.interbusNumber' => ['Numero della lettera di vettura Interbus', 'Interbus waybill number', 'Interbus-Frachtbriefnummer', 'Numéro de lettre de voiture Interbus'],
            'passengerTransport.field.issuingMunicipality' => ['Comune di rilascio', 'Issuing municipality', 'Ausstellende Gemeinde', 'Commune d’émission'],
            'passengerTransport.field.itKm' => ['km in Italia', 'km in Italy', 'km in Italien', 'km en Italie'],
            'passengerTransport.field.km' => ['km', 'km', 'km', 'km'],
            'passengerTransport.field.label' => ['Descrizione', 'Description', 'Bezeichnung', 'Libellé'],
            'passengerTransport.field.leadPassenger' => ['Passeggero principale', 'Lead passenger', 'Hauptpassagier', 'Passager principal'],
            'passengerTransport.field.licenseCategories' => ['Categorie della patente', 'Licence categories', 'Führerscheinkategorien', 'Catégories du permis'],
            'passengerTransport.field.licenseExpiry' => ['Scadenza della patente', 'Licence expiry', 'Ablauf des Führerscheins', 'Expiration du permis'],
            'passengerTransport.field.licenseNumber' => ['Numero della patente', 'Licence number', 'Führerscheinnummer', 'Numéro du permis'],
            'passengerTransport.field.licensePlate' => ['Targa', 'Licence plate', 'Kontrollschild', 'Plaque d’immatriculation'],
            'passengerTransport.field.logo' => ['Logo', 'Logo', 'Logo', 'Logo'],
            'passengerTransport.field.luggage' => ['Bagagli', 'Luggage', 'Gepäck', 'Bagages'],
            'passengerTransport.field.minutes' => ['Minuti', 'Minutes', 'Minuten', 'Minutes'],
            'passengerTransport.field.month' => ['Mese', 'Month', 'Monat', 'Mois'],
            'passengerTransport.field.municipalUrban' => ['Servizio urbano comunale (esente Art. 10 n. 14)', 'Municipal urban service (exempt Art. 10 n. 14)', 'Städtischer Linienverkehr (befreit nach Art. 10 Nr. 14)', 'Service urbain communal (exonéré art. 10 n. 14)'],
            'passengerTransport.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'passengerTransport.field.nationality' => ['Nazionalità (ISO)', 'Nationality (ISO)', 'Nationalität (ISO)', 'Nationalité (ISO)'],
            'passengerTransport.field.nature' => ['Natura IVA', 'VAT nature', 'MWST-Art', 'Nature TVA'],
            'passengerTransport.field.nccAuthorization' => ['Autorizzazione NCC', 'NCC authorization', 'NCC-Bewilligung', 'Autorisation NCC'],
            'passengerTransport.field.net' => ['Imponibile', 'Net', 'Netto', 'Net'],
            'passengerTransport.field.odometer' => ['Contachilometri', 'Odometer', 'Kilometerstand', 'Compteur kilométrique'],
            'passengerTransport.field.origin' => ['Origine', 'Origin', 'Herkunft', 'Origine'],
            'passengerTransport.field.otherKm' => ['km in altri paesi', 'km in other countries', 'km in anderen Ländern', 'km dans d’autres pays'],
            'passengerTransport.field.passengerName' => ['Nome e cognome', 'Full name', 'Vor- und Nachname', 'Nom et prénom'],
            'passengerTransport.field.passengers' => ['Passeggeri', 'Passengers', 'Fahrgäste', 'Passagers'],
            'passengerTransport.field.payout' => ['Compenso al collega', 'Colleague payout', 'Vergütung an den Kollegen', 'Rémunération du confrère'],
            'passengerTransport.field.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'passengerTransport.field.phone' => ['Telefono', 'Phone', 'Telefon', 'Téléphone'],
            'passengerTransport.field.pickup' => ['Ritiro', 'Pickup', 'Abholung', 'Prise en charge'],
            'passengerTransport.field.pickupAddress' => ['Indirizzo di ritiro', 'Pickup address', 'Abholadresse', 'Adresse de prise en charge'],
            'passengerTransport.field.pickupAt' => ['Data e ora di ritiro', 'Pickup date and time', 'Abholdatum und -zeit', 'Date et heure de prise en charge'],
            'passengerTransport.field.pickupCountry' => ['Paese di ritiro', 'Pickup country', 'Abholland', 'Pays de prise en charge'],
            'passengerTransport.field.pickupLat' => ['Latitudine ritiro', 'Pickup latitude', 'Breitengrad Abholung', 'Latitude de prise en charge'],
            'passengerTransport.field.pickupLng' => ['Longitudine ritiro', 'Pickup longitude', 'Längengrad Abholung', 'Longitude de prise en charge'],
            'passengerTransport.field.plannedBreak' => ['Pausa prevista (min)', 'Planned break (min)', 'Geplante Pause (Min.)', 'Pause prévue (min)'],
            'passengerTransport.field.price' => ['Prezzo', 'Price', 'Preis', 'Prix'],
            'passengerTransport.field.remaining' => ['Guida residua', 'Remaining driving time', 'Verbleibende Lenkzeit', 'Temps de conduite restant'],
            'passengerTransport.field.resource' => ['Risorsa', 'Resource', 'Ressource', 'Ressource'],
            'passengerTransport.field.route' => ['Percorso', 'Route', 'Strecke', 'Trajet'],
            'passengerTransport.field.scheduledArrival' => ['Arrivo previsto del volo', 'Scheduled flight arrival', 'Planmässige Flugankunft', 'Arrivée prévue du vol'],
            'passengerTransport.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'passengerTransport.field.serviceEnd' => ['Fine del servizio', 'Service end', 'Dienstende', 'Fin du service'],
            'passengerTransport.field.serviceEndKm' => ['km a fine servizio', 'km at service end', 'km bei Dienstende', 'km en fin de service'],
            'passengerTransport.field.serviceStart' => ['Inizio del servizio', 'Service start', 'Dienstbeginn', 'Début du service'],
            'passengerTransport.field.serviceStartKm' => ['km a inizio servizio', 'km at service start', 'km bei Dienstbeginn', 'km au début du service'],
            'passengerTransport.field.serviceType' => ['Tipo di servizio', 'Service type', 'Dienstart', 'Type de service'],
            'passengerTransport.field.signatureHash' => ['Impronta della firma (SHA-256)', 'Signature hash (SHA-256)', 'Signaturhash (SHA-256)', 'Empreinte de la signature (SHA-256)'],
            'passengerTransport.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'passengerTransport.field.tachographCardExpiry' => ['Scadenza della carta del cronotachigrafo', 'Tachograph card expiry', 'Ablauf der Fahrerkarte', 'Expiration de la carte de tachygraphe'],
            'passengerTransport.field.tachographCardNumber' => ['Numero della carta del cronotachigrafo', 'Tachograph card number', 'Nummer der Fahrerkarte', 'Numéro de la carte de tachygraphe'],
            'passengerTransport.field.territory' => ['Territorio', 'Territory', 'Gebiet', 'Territoire'],
            'passengerTransport.field.total' => ['Totale', 'Total', 'Total', 'Total'],
            'passengerTransport.field.totalKm' => ['km totali', 'Total km', 'km total', 'km totaux'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fieldsContracts(): array
    {
        return [
            'passengerTransport.field.cqcExpiry' => ['Scadenza della CQC Persone', 'CQC Persone expiry', 'Ablauf der CQC Persone', 'Expiration de la CQC Persone'],
            'passengerTransport.field.cqcNumber' => ['Numero della CQC Persone', 'CQC Persone number', 'Nummer der CQC Persone', 'Numéro de la CQC Persone'],
            'passengerTransport.field.czvExpiry' => ['Scadenza del CZV', 'CZV expiry', 'Ablauf des CZV', 'Expiration du CZV'],
            'passengerTransport.field.czvNumber' => ['Numero del CZV', 'CZV number', 'Nummer des CZV', 'Numéro du CZV'],
            'passengerTransport.field.trips' => ['Corse', 'Trips', 'Fahrten', 'Courses'],
            'passengerTransport.field.uploadedAt' => ['Caricato il', 'Uploaded on', 'Hochgeladen am', 'Téléchargé le'],
            'passengerTransport.field.validFrom' => ['Valido dal', 'Valid from', 'Gültig ab', 'Valable dès'],
            'passengerTransport.field.validity' => ['Validità', 'Validity', 'Gültigkeit', 'Validité'],
            'passengerTransport.field.validTo' => ['Valido fino al', 'Valid until', 'Gültig bis', 'Valable jusqu’au'],
            'passengerTransport.field.vat' => ['IVA', 'VAT', 'MWST', 'TVA'],
            'passengerTransport.field.vatRate' => ['Aliquota', 'Rate', 'Satz', 'Taux'],
            'passengerTransport.field.vehicle' => ['Veicolo', 'Vehicle', 'Fahrzeug', 'Véhicule'],
            'passengerTransport.field.vehicleCategory' => ['Categoria del veicolo', 'Vehicle category', 'Fahrzeugkategorie', 'Catégorie du véhicule'],
            'passengerTransport.field.welcomeText' => ['Testo del cartello di benvenuto', 'Welcome sign text', 'Text des Willkommensschilds', 'Texte du panneau d’accueil'],
            'passengerTransport.field.acquiredPayout' => ['Compensi dovuti ai colleghi', 'Payouts owed to colleagues', 'Den Kollegen geschuldete Vergütungen', 'Rémunérations dues aux confrères'],
            'passengerTransport.field.cededPayout' => ['Compensi ceduti', 'Payouts for ceded services', 'Vergütungen für abgetretene Leistungen', 'Rémunérations des services cédés'],
            'passengerTransport.field.balance' => ['Saldo netto', 'Net balance', 'Nettosaldo', 'Solde net'],
            'passengerTransport.field.routeCode' => ['Codice tratta', 'Route code', 'Streckencode', 'Code du trajet'],
            'passengerTransport.field.routes' => ['Tratte', 'Routes', 'Strecken', 'Trajets'],
            'passengerTransport.field.monthlyFlat' => ['Forfait mensile', 'Monthly flat amount', 'Monatspauschale', 'Forfait mensuel'],
            'passengerTransport.document.license' => ['Patente', 'Licence', 'Führerschein', 'Permis'],
            'passengerTransport.document.tachographCard' => ['Carta cronotachigrafo', 'Tachograph card', 'Fahrerkarte', 'Carte tachygraphe'],
            'passengerTransport.gauge.daily' => ['Giorno', 'Day', 'Tag', 'Jour'],
            'passengerTransport.gauge.weekly' => ['Settimana', 'Week', 'Woche', 'Semaine'],
            'passengerTransport.gauge.remaining' => ['Residuo', 'Remaining', 'Verbleibend', 'Restant'],
            'passengerTransport.row.unassigned' => ['Da assegnare', 'Unassigned', 'Nicht zugewiesen', 'Non attribuées'],
            'passengerTransport.row.subcontracted' => ['Cedute a colleghi', 'Subcontracted', 'An Kollegen abgetreten', 'Sous-traitées'],
            'passengerTransport.legend.transfer' => ['Corsa', 'Trip', 'Fahrt', 'Course'],
            'passengerTransport.legend.crossBorder' => ['Transfrontaliera', 'Cross-border', 'Grenzüberschreitend', 'Transfrontalière'],
            'passengerTransport.legend.delayed' => ['Volo in ritardo', 'Delayed flight', 'Verspäteter Flug', 'Vol retardé'],
            'passengerTransport.legend.subcontracted' => ['Ceduta a un collega', 'Ceded to a colleague', 'An einen Kollegen abgetreten', 'Cédée à un confrère'],
            'passengerTransport.empty.archives' => ['Nessun file caricato.', 'No files uploaded.', 'Keine Dateien hochgeladen.', 'Aucun fichier téléchargé.'],
            'passengerTransport.empty.bookings' => ['Nessuna prenotazione.', 'No bookings.', 'Keine Buchungen.', 'Aucune réservation.'],
            'passengerTransport.empty.contracts' => ['Nessun contratto.', 'No contracts.', 'Keine Verträge.', 'Aucun contrat.'],
            'passengerTransport.empty.dispatch' => ['Nessuna corsa nel giorno scelto.', 'No trips on the chosen day.', 'Keine Fahrten am gewählten Tag.', 'Aucune course le jour choisi.'],
            'passengerTransport.empty.drivers' => ['Nessun autista registrato.', 'No drivers recorded.', 'Keine Fahrer erfasst.', 'Aucun chauffeur enregistré.'],
            'passengerTransport.empty.ledger' => ['Nessuno scambio di servizi nel mese.', 'No service exchanges this month.', 'Keine Leistungsaustausche in diesem Monat.', 'Aucun échange de services ce mois-ci.'],
            'passengerTransport.empty.preview' => ['Nessuna corsa o forfait da fatturare nel mese.', 'No trips or flat amounts to invoice this month.', 'Keine Fahrten oder Pauschalen zum Fakturieren in diesem Monat.', 'Aucune course ou forfait à facturer ce mois-ci.'],
            'passengerTransport.empty.quote' => ['Compila il percorso per vedere i km per paese e la ripartizione dell’IVA.', 'Fill in the route to see the kilometres by country and the VAT split.', 'Füllen Sie die Strecke aus, um die Kilometer pro Land und die MWST-Aufteilung zu sehen.', 'Renseignez le trajet pour voir les kilomètres par pays et la ventilation de la TVA.'],
            'passengerTransport.empty.sheet' => ['Nessun foglio di servizio per questa prenotazione.', 'No service sheet for this booking.', 'Kein Dienstblatt für diese Buchung.', 'Aucune feuille de service pour cette réservation.'],
            'passengerTransport.empty.vehicles' => ['Nessun veicolo.', 'No vehicles.', 'Keine Fahrzeuge.', 'Aucun véhicule.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'passengerTransport.action.acknowledgeWarnings' => ['Ho letto gli avvisi e assegno comunque', 'I have read the warnings and assign anyway', 'Ich habe die Hinweise gelesen und weise trotzdem zu', 'J’ai lu les avertissements et j’attribue quand même'],
            'passengerTransport.action.addPassenger' => ['Aggiungi passeggero', 'Add passenger', 'Fahrgast hinzufügen', 'Ajouter un passager'],
            'passengerTransport.action.addRoute' => ['Aggiungi tratta', 'Add route', 'Strecke hinzufügen', 'Ajouter un trajet'],
            'passengerTransport.action.applyExchange' => ['Applica lo scambio', 'Apply the exchange', 'Austausch anwenden', 'Appliquer l’échange'],
            'passengerTransport.action.assign' => ['Assegna', 'Assign', 'Zuweisen', 'Attribuer'],
            'passengerTransport.action.backToBookings' => ['Torna alle prenotazioni', 'Back to bookings', 'Zurück zu den Buchungen', 'Retour aux réservations'],
            'passengerTransport.action.bookConcierge' => ['Prenota per l’ospite', 'Book for the guest', 'Für den Gast buchen', 'Réserver pour le client'],
            'passengerTransport.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'passengerTransport.action.checkCompliance' => ['Verifica conformità', 'Check compliance', 'Konformität prüfen', 'Vérifier la conformité'],
            'passengerTransport.action.close' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
            'passengerTransport.action.closeSheet' => ['Chiudi e firma il foglio', 'Close and sign the sheet', 'Blatt abschliessen und signieren', 'Clôturer et signer la feuille'],
            'passengerTransport.action.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'passengerTransport.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'passengerTransport.action.download' => ['Scarica', 'Download', 'Herunterladen', 'Télécharger'],
            'passengerTransport.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'passengerTransport.action.fullScreenSign' => ['Cartello a schermo intero', 'Full screen sign', 'Schild im Vollbild', 'Panneau en plein écran'],
            'passengerTransport.action.generate' => ['Genera foglio e fattura', 'Generate sheet and invoice', 'Blatt und Rechnung erzeugen', 'Générer la feuille et la facture'],
            'passengerTransport.action.interbusPdf' => ['PDF Interbus', 'Interbus PDF', 'Interbus-PDF', 'PDF Interbus'],
            'passengerTransport.action.invoice' => ['Fattura', 'Invoice', 'Fakturieren', 'Facturer'],
            'passengerTransport.action.invoiceMonth' => ['Fattura il mese', 'Invoice the month', 'Monat fakturieren', 'Facturer le mois'],
            'passengerTransport.action.newBooking' => ['Nuova prenotazione', 'New booking', 'Neue Buchung', 'Nouvelle réservation'],
            'passengerTransport.action.newContract' => ['Nuovo contratto', 'New contract', 'Neuer Vertrag', 'Nouveau contrat'],
            'passengerTransport.action.newDriver' => ['Nuovo autista', 'New driver', 'Neuer Fahrer', 'Nouveau chauffeur'],
            'passengerTransport.action.newVehicle' => ['Nuovo veicolo', 'New vehicle', 'Neues Fahrzeug', 'Nouveau véhicule'],
            'passengerTransport.action.nextDay' => ['Giorno successivo', 'Next day', 'Nächster Tag', 'Jour suivant'],
            'passengerTransport.action.openSheet' => ['Foglio di servizio', 'Service sheet', 'Dienstblatt', 'Feuille de service'],
            'passengerTransport.action.previousDay' => ['Giorno precedente', 'Previous day', 'Vorheriger Tag', 'Jour précédent'],
            'passengerTransport.action.refresh' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'passengerTransport.action.remove' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'passengerTransport.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'passengerTransport.action.selectAll' => ['Seleziona tutti', 'Select all', 'Alle auswählen', 'Tout sélectionner'],
            'passengerTransport.action.sheetPdf' => ['PDF del foglio', 'Sheet PDF', 'Blatt-PDF', 'PDF de la feuille'],
            'passengerTransport.action.signPdf' => ['PDF del cartello', 'Sign PDF', 'Schild-PDF', 'PDF du panneau'],
            'passengerTransport.action.trackFlight' => ['Aggiorna stato del volo', 'Track the flight', 'Flugstatus aktualisieren', 'Suivre le vol'],
            'passengerTransport.action.transmitFdse' => ['Trasmetti FDSE al MIT', 'Transmit FDSE to the MIT', 'FDSE an das MIT übermitteln', 'Transmettre la FDSE au MIT'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function confirmations(): array
    {
        return [
            'passengerTransport.confirm.cancelBooking' => ['Annullare la prenotazione?', 'Cancel the booking?', 'Buchung stornieren?', 'Annuler la réservation ?'],
            'passengerTransport.confirm.closeSheet' => ['Chiudere e firmare il foglio? Dopo non sarà più modificabile.', 'Close and sign the sheet? It can no longer be changed afterwards.', 'Blatt abschliessen und signieren? Danach ist es nicht mehr änderbar.', 'Clôturer et signer la feuille ? Elle ne sera plus modifiable.'],
            'passengerTransport.confirm.deleteContract' => ['Eliminare il contratto?', 'Delete the contract?', 'Vertrag löschen?', 'Supprimer le contrat ?'],
            'passengerTransport.confirm.deleteVehicle' => ['Eliminare il veicolo?', 'Delete the vehicle?', 'Fahrzeug löschen?', 'Supprimer le véhicule ?'],
            'passengerTransport.confirm.invoiceBooking' => ['Creare la fattura della corsa con la ripartizione IVA per tratta?', 'Create the invoice of the trip with the VAT split by leg?', 'Rechnung der Fahrt mit MWST-Aufteilung pro Strecke erstellen?', 'Créer la facture de la course avec la ventilation de la TVA par trajet ?'],
            'passengerTransport.confirm.invoiceMonth' => ['Emettere le fatture del mese per {count} clienti selezionati?', 'Issue the month’s invoices for {count} selected customers?', 'Die Monatsrechnungen für {count} ausgewählte Kunden ausstellen?', 'Émettre les factures du mois pour {count} clients sélectionnés ?'],
            'passengerTransport.confirm.transmitFdse' => ['Trasmettere il foglio al MIT (FDSE)?', 'Transmit the sheet to the MIT (FDSE)?', 'Das Blatt an das MIT (FDSE) übermitteln?', 'Transmettre la feuille au MIT (FDSE) ?'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'passengerTransport.section.booking' => ['Prenotazione', 'Booking', 'Buchung', 'Réservation'],
            'passengerTransport.section.compliance' => ['Conformità normativa', 'Legal compliance', 'Rechtskonformität', 'Conformité légale'],
            'passengerTransport.section.concierge' => ['Portale concierge hotel', 'Hotel concierge portal', 'Hotel-Concierge-Portal', 'Portail conciergerie hôtel'],
            'passengerTransport.section.conciergeBooking' => ['Prenotazione per un ospite', 'Booking for a guest', 'Buchung für einen Gast', 'Réservation pour un client'],
            'passengerTransport.section.ddd' => ['Archivio file DDD', 'DDD file archive', 'DDD-Dateiarchiv', 'Archives des fichiers DDD'],
            'passengerTransport.section.driver' => ['Autista', 'Driver', 'Fahrer', 'Chauffeur'],
            'passengerTransport.section.drivers' => ['Autisti e documenti', 'Drivers and documents', 'Fahrer und Dokumente', 'Chauffeurs et documents'],
            'passengerTransport.section.drivingTime' => ['Tempi di guida', 'Driving time', 'Lenkzeiten', 'Temps de conduite'],
            'passengerTransport.section.exchange' => ['Scambio servizi', 'Service exchange', 'Leistungsaustausch', 'Échange de services'],
            'passengerTransport.section.exchangedServices' => ['Servizi scambiati', 'Exchanged services', 'Ausgetauschte Leistungen', 'Services échangés'],
            'passengerTransport.section.fare' => ['Tariffa', 'Fare', 'Preis', 'Tarif'],
            'passengerTransport.section.flight' => ['Volo', 'Flight', 'Flug', 'Vol'],
            'passengerTransport.section.generate' => ['Genera foglio di servizio e fattura', 'Generate the service sheet and the invoice', 'Dienstblatt und Rechnung erzeugen', 'Générer la feuille de service et la facture'],
            'passengerTransport.section.hotelContract' => ['Contratto con l’hotel', 'Hotel contract', 'Hotelvertrag', 'Contrat avec l’hôtel'],
            'passengerTransport.section.infringements' => ['Infrazioni', 'Infringements', 'Verstösse', 'Infractions'],
            'passengerTransport.section.issuedInvoices' => ['Fatture emesse', 'Issued invoices', 'Ausgestellte Rechnungen', 'Factures émises'],
            'passengerTransport.section.kilometres' => ['Chilometri per paese', 'Kilometres by country', 'Kilometer pro Land', 'Kilomètres par pays'],
            'passengerTransport.section.manifest' => ['Elenco dei passeggeri', 'Passenger manifest', 'Passagierliste', 'Liste des passagers'],
            'passengerTransport.section.monthEnd' => ['Fatturazione di fine mese', 'Month-end invoicing', 'Monatsabschluss-Fakturierung', 'Facturation de fin de mois'],
            'passengerTransport.section.partners' => ['Saldi con i vettori partner', 'Balances with partner carriers', 'Salden mit Partner-Transporteuren', 'Soldes avec les transporteurs partenaires'],
            'passengerTransport.section.quickAssign' => ['Assegnazione rapida', 'Quick assignment', 'Schnellzuweisung', 'Attribution rapide'],
            'passengerTransport.section.rateCard' => ['Listino delle tratte', 'Route rate card', 'Streckenpreisliste', 'Grille tarifaire des trajets'],
            'passengerTransport.section.route' => ['Percorso', 'Route', 'Strecke', 'Trajet'],
            'passengerTransport.section.schoolBus' => ['Contratti scuolabus', 'School bus contracts', 'Schulbusverträge', 'Contrats de bus scolaire'],
            'passengerTransport.section.schoolContract' => ['Contratto scuolabus', 'School bus contract', 'Schulbusvertrag', 'Contrat de bus scolaire'],
            'passengerTransport.section.service' => ['Servizio e passeggeri', 'Service and passengers', 'Dienst und Fahrgäste', 'Service et passagers'],
            'passengerTransport.section.sheet' => ['Foglio', 'Sheet', 'Blatt', 'Feuille'],
            'passengerTransport.section.vatSplit' => ['Ripartizione dell’IVA', 'VAT split', 'MWST-Aufteilung', 'Ventilation de la TVA'],
            'passengerTransport.section.vehicle' => ['Veicolo', 'Vehicle', 'Fahrzeug', 'Véhicule'],
            'passengerTransport.section.welcomeSign' => ['Cartello di benvenuto VIP', 'VIP welcome sign', 'VIP-Willkommensschild', 'Panneau d’accueil VIP'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function hints(): array
    {
        return [
            'passengerTransport.hint.dddOwner' => ['Il file viene attribuito all’autista a cui appartiene la carta; scegli un autista sopra solo se la carta non è registrata. Conservazione: 5 anni.', 'The file is attributed to the driver who owns the card; choose a driver above only if the card is not recorded. Retention: 5 years.', 'Die Datei wird dem Fahrer zugeordnet, dem die Karte gehört; wählen Sie oben nur dann einen Fahrer, wenn die Karte nicht erfasst ist. Aufbewahrung: 5 Jahre.', 'Le fichier est attribué au chauffeur titulaire de la carte ; choisissez un chauffeur ci-dessus seulement si la carte n’est pas enregistrée. Conservation : 5 ans.'],
            'passengerTransport.hint.dddUpload' => ['Trascina qui il file .DDD della carta autista o selezionalo', 'Drop the .DDD file of the driver card here or select it', 'Ziehen Sie die .DDD-Datei der Fahrerkarte hierher oder wählen Sie sie aus', 'Déposez ici le fichier .DDD de la carte conducteur ou sélectionnez-le'],
            'passengerTransport.hint.documentsExpiring' => ['{count} documenti degli autisti sono scaduti o stanno per scadere.', '{count} driver documents are expired or about to expire.', '{count} Fahrerdokumente sind abgelaufen oder laufen bald ab.', '{count} documents de chauffeurs sont expirés ou sur le point d’expirer.'],
            'passengerTransport.hint.flightsAttention' => ['{count} voli richiedono attenzione (ritardi o cancellazioni).', '{count} flights need attention (delays or cancellations).', '{count} Flüge brauchen Aufmerksamkeit (Verspätungen oder Annullierungen).', '{count} vols demandent de l’attention (retards ou annulations).'],
            'passengerTransport.hint.generate' => ['Salva i dati del foglio e, a scelta, lo chiude e firma, lo trasmette al MIT e crea la fattura con la ripartizione IVA per tratta.', 'Saves the sheet data and, as chosen, closes and signs it, transmits it to the MIT and creates the invoice with the VAT split by leg.', 'Speichert die Blattdaten und schliesst und signiert es nach Wahl, übermittelt es an das MIT und erstellt die Rechnung mit MWST-Aufteilung pro Strecke.', 'Enregistre les données de la feuille et, au choix, la clôture et la signe, la transmet au MIT et crée la facture avec la ventilation de la TVA par trajet.'],
            'passengerTransport.hint.kilometresManual' => ['I chilometri per paese sono calcolati dal percorso e dal valico; compila i campi solo per sostituirli con i valori reali.', 'The kilometres by country are calculated from the route and the border crossing; fill in the fields only to replace them with the real values.', 'Die Kilometer pro Land werden aus Strecke und Grenzübergang berechnet; füllen Sie die Felder nur aus, um sie durch die tatsächlichen Werte zu ersetzen.', 'Les kilomètres par pays sont calculés d’après le trajet et le passage de frontière ; renseignez les champs seulement pour les remplacer par les valeurs réelles.'],
            'passengerTransport.hint.kmBar' => ['Italia {it}%, Svizzera {ch}%, altri paesi {other}%', 'Italy {it}%, Switzerland {ch}%, other countries {other}%', 'Italien {it}%, Schweiz {ch}%, andere Länder {other}%', 'Italie {it}%, Suisse {ch}%, autres pays {other}%'],
            'passengerTransport.hint.monthEnd' => ['Raggruppa per cliente le corse completate del mese e i forfait mensili dei contratti scuolabus in una sola fattura; ogni forfait si fattura una sola volta al mese.', 'Groups the completed trips of the month and the monthly flat amounts of school bus contracts into one invoice per customer; each flat amount is billed only once a month.', 'Fasst pro Kunde die abgeschlossenen Fahrten des Monats und die Monatspauschalen der Schulbusverträge in einer Rechnung zusammen; jede Pauschale wird nur einmal pro Monat verrechnet.', 'Regroupe par client les courses terminées du mois et les forfaits mensuels des contrats de bus scolaire en une seule facture ; chaque forfait n’est facturé qu’une fois par mois.'],
            'passengerTransport.hint.noFindings' => ['Nessuna violazione né avviso.', 'No violations or warnings.', 'Keine Verstösse oder Hinweise.', 'Aucune infraction ni avertissement.'],
            'passengerTransport.hint.pickupHolds' => ['L’orario di ritiro resta valido.', 'The pickup time still holds.', 'Die Abholzeit bleibt gültig.', 'L’heure de prise en charge reste valable.'],
            'passengerTransport.hint.pickupMoved' => ['L’orario di ritiro è stato spostato in base al volo.', 'The pickup time was moved according to the flight.', 'Die Abholzeit wurde gemäss dem Flug verschoben.', 'L’heure de prise en charge a été déplacée selon le vol.'],
            'passengerTransport.hint.vatItaly' => ['IVA italiana al 10% (DPR 633/72, tabella A parte III n. 127-novies) per la tratta in Italia; la tratta all’estero è fuori campo IVA (Art. 7-quater e Art. 9). Le aliquote e la natura vanno confermate con il consulente fiscale.', 'Italian VAT at 10% (DPR 633/72, table A part III n. 127-novies) for the leg in Italy; the leg abroad is outside the scope of VAT (Art. 7-quater and Art. 9). Rates and nature must be confirmed with the tax advisor.', 'Italienische MWST zu 10% (DPR 633/72, Tabelle A Teil III Nr. 127-novies) für die Strecke in Italien; die Auslandstrecke ist nicht steuerbar (Art. 7-quater und Art. 9). Sätze und Art sind mit dem Steuerberater zu bestätigen.', 'TVA italienne à 10% (DPR 633/72, tableau A partie III n. 127-novies) pour le trajet en Italie ; le trajet à l’étranger est hors champ de la TVA (art. 7-quater et art. 9). Les taux et la nature sont à confirmer avec le conseiller fiscal.'],
            'passengerTransport.hint.vatSwitzerland' => ['MWST svizzera all’8,1% sui chilometri in Svizzera; il trasporto internazionale può essere esente (Art. 23 MWSTG, Info IVA 11). Aliquote e condizioni vanno confermate con il consulente fiscale.', 'Swiss MWST at 8.1% on the kilometres in Switzerland; international transport may be exempt (Art. 23 MWSTG, Info MWST 11). Rates and conditions must be confirmed with the tax advisor.', 'Schweizer MWST zu 8,1% auf die Kilometer in der Schweiz; der internationale Transport kann befreit sein (Art. 23 MWSTG, MWST-Info 11). Sätze und Bedingungen sind mit dem Steuerberater zu bestätigen.', 'TVA suisse à 8,1% sur les kilomètres en Suisse ; le transport international peut être exonéré (art. 23 LTVA, Info TVA 11). Les taux et conditions sont à confirmer avec le conseiller fiscal.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'passengerTransport.notification.bookingInvoiced' => ['Fattura creata.', 'Invoice created.', 'Rechnung erstellt.', 'Facture créée.'],
            'passengerTransport.notification.bookingSaved' => ['Prenotazione salvata.', 'Booking saved.', 'Buchung gespeichert.', 'Réservation enregistrée.'],
            'passengerTransport.notification.bookingUpdated' => ['Stato aggiornato.', 'Status updated.', 'Status aktualisiert.', 'Statut mis à jour.'],
            'passengerTransport.notification.conciergeBooked' => ['Prenotazione {code} creata.', 'Booking {code} created.', 'Buchung {code} erstellt.', 'Réservation {code} créée.'],
            'passengerTransport.notification.contractDeleted' => ['Contratto eliminato.', 'Contract deleted.', 'Vertrag gelöscht.', 'Contrat supprimé.'],
            'passengerTransport.notification.contractSaved' => ['Contratto salvato.', 'Contract saved.', 'Vertrag gespeichert.', 'Contrat enregistré.'],
            'passengerTransport.notification.dddUploaded' => ['File archiviato e analizzato.', 'File archived and analysed.', 'Datei archiviert und analysiert.', 'Fichier archivé et analysé.'],
            'passengerTransport.notification.driverSaved' => ['Autista salvato.', 'Driver saved.', 'Fahrer gespeichert.', 'Chauffeur enregistré.'],
            'passengerTransport.notification.exchangeApplied' => ['Scambio registrato.', 'Exchange recorded.', 'Austausch erfasst.', 'Échange enregistré.'],
            'passengerTransport.notification.invoiceCreated' => ['Fattura {number} creata.', 'Invoice {number} created.', 'Rechnung {number} erstellt.', 'Facture {number} créée.'],
            'passengerTransport.notification.logoUploaded' => ['Logo caricato.', 'Logo uploaded.', 'Logo hochgeladen.', 'Logo téléchargé.'],
            'passengerTransport.notification.monthInvoiced' => ['Fatture del mese emesse.', 'Invoices of the month issued.', 'Monatsrechnungen ausgestellt.', 'Factures du mois émises.'],
            'passengerTransport.notification.sheetClosed' => ['Foglio chiuso e firmato.', 'Sheet closed and signed.', 'Blatt abgeschlossen und signiert.', 'Feuille clôturée et signée.'],
            'passengerTransport.notification.sheetGenerated' => ['Foglio generato.', 'Sheet generated.', 'Blatt erzeugt.', 'Feuille générée.'],
            'passengerTransport.notification.sheetOpened' => ['Foglio aperto.', 'Sheet opened.', 'Blatt eröffnet.', 'Feuille ouverte.'],
            'passengerTransport.notification.sheetSaved' => ['Foglio salvato.', 'Sheet saved.', 'Blatt gespeichert.', 'Feuille enregistrée.'],
            'passengerTransport.notification.sheetTransmitted' => ['Foglio trasmesso al MIT.', 'Sheet transmitted to the MIT.', 'Blatt an das MIT übermittelt.', 'Feuille transmise au MIT.'],
            'passengerTransport.notification.vehicleDeleted' => ['Veicolo eliminato.', 'Vehicle deleted.', 'Fahrzeug gelöscht.', 'Véhicule supprimé.'],
            'passengerTransport.notification.vehicleSaved' => ['Veicolo salvato.', 'Vehicle saved.', 'Fahrzeug gespeichert.', 'Véhicule enregistré.'],
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
