<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the green space maintenance vertical (Mod 126, Issue #372): the dashboard with the season KPIs, the green space sites,
 * the seasonal and condominium contracts with the passage counter and the invoice generation (4% condominium withholding), the multi-crew
 * route planner with the weather alerts and the intervention log, the phytosanitary diary (Quaderno di Campagna) with the licence check,
 * the green waste slips (CER / OTR), the notifications and the codes the API reports (greenCare.error.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH. Placeholders are {name}.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI), the names of the licences (PER, PNRP, OPF, PAN), the waste codes (CER, OTR)
 * and the transport documents (FIR, DDT) stay untranslated.
 */
final class GreenCareFrontendTranslations
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
            self::contractsAndBilling(),
            self::planner(),
            self::treatmentsAndWaste(),
            self::notifications(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.GREEN_CARE' => ['Verde e giardinaggio', 'Green care', 'Grünpflege und Gartenbau', 'Entretien des espaces verts'],
            'NAV.GREEN_CARE_DASHBOARD' => ['Cruscotto stagione', 'Season dashboard', 'Saison-Übersicht', 'Tableau de bord de la saison'],
            'NAV.GREEN_CARE_SITES' => ['Siti e giardini', 'Sites and gardens', 'Standorte und Gärten', 'Sites et jardins'],
            'NAV.GREEN_CARE_CONTRACTS' => ['Contratti stagionali e condomini', 'Seasonal and condominium contracts', 'Saison- und Stockwerkeigentümerverträge', 'Contrats saisonniers et copropriétés'],
            'NAV.GREEN_CARE_PLANNER' => ['Pianificazione giro squadre', 'Crew route planning', 'Routenplanung der Teams', 'Planification des tournées des équipes'],
            'NAV.GREEN_CARE_TREATMENTS' => ['Quaderno di campagna / registro fitosanitari', 'Field diary / plant protection register', 'Feldbuch / Pflanzenschutzregister', 'Cahier de campagne / registre phytosanitaire'],
            'NAV.GREEN_CARE_WASTE' => ['Formulari smaltimento verde CER / OTR', 'Green waste slips CER / OTR', 'Grünabfall-Begleitscheine CER / OTR', 'Bordereaux de déchets verts CER / OTR'],
            'PAGE_TITLE.GREEN_CARE_DASHBOARD' => ['Verde e giardinaggio: cruscotto della stagione', 'Green care: season dashboard', 'Grünpflege: Saison-Übersicht', 'Espaces verts : tableau de bord de la saison'],
            'PAGE_TITLE.GREEN_CARE_SITES' => ['Siti e giardini', 'Sites and gardens', 'Standorte und Gärten', 'Sites et jardins'],
            'PAGE_TITLE.GREEN_CARE_CONTRACTS' => ['Contratti stagionali e condomini', 'Seasonal and condominium contracts', 'Saison- und Stockwerkeigentümerverträge', 'Contrats saisonniers et copropriétés'],
            'PAGE_TITLE.GREEN_CARE_PLANNER' => ['Pianificazione giro squadre', 'Crew route planning', 'Routenplanung der Teams', 'Planification des tournées des équipes'],
            'NAV.GREEN_CARE_CREWS' => ['Squadre e patentini', 'Crews and licences', 'Teams und Bewilligungen', 'Équipes et certificats'],
            'PAGE_TITLE.GREEN_CARE_CREWS' => ['Squadre e patentini degli operatori', 'Crews and operator licences', 'Teams und Bewilligungen der Operatoren', 'Équipes et certificats des opérateurs'],
            'PAGE_TITLE.GREEN_CARE_TREATMENTS' => ['Quaderno di campagna / registro dei trattamenti fitosanitari', 'Field diary / plant protection treatment register', 'Feldbuch / Register der Pflanzenschutzbehandlungen', 'Cahier de campagne / registre des traitements phytosanitaires'],
            'PAGE_TITLE.GREEN_CARE_WASTE' => ['Formulari di smaltimento del verde CER / OTR', 'Green waste disposal slips CER / OTR', 'Entsorgungsscheine für Grünabfall CER / OTR', 'Bordereaux d\'élimination des déchets verts CER / OTR'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'greenCare.enum.billingMode.fixed_installments' => ['Rate fisse', 'Fixed installments', 'Feste Raten', 'Acomptes fixes'],
            'greenCare.enum.billingMode.per_passage' => ['A passaggio', 'Per passage', 'Pro Einsatz', 'Au passage'],
            'greenCare.enum.billingMode.time_and_material' => ['A consuntivo (ore e materiali)', 'Time and material', 'Nach Aufwand (Stunden und Material)', 'En régie (heures et matériaux)'],
            'greenCare.enum.installmentFrequency.monthly' => ['Mensile', 'Monthly', 'Monatlich', 'Mensuelle'],
            'greenCare.enum.installmentFrequency.quarterly' => ['Trimestrale', 'Quarterly', 'Vierteljährlich', 'Trimestrielle'],
            'greenCare.enum.installmentFrequency.seasonal_split' => ['Ripartita sulla stagione', 'Split over the season', 'Auf die Saison verteilt', 'Répartie sur la saison'],
            'greenCare.enum.contractStatus.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'greenCare.enum.contractStatus.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'greenCare.enum.contractStatus.suspended' => ['Sospeso', 'Suspended', 'Ausgesetzt', 'Suspendu'],
            'greenCare.enum.contractStatus.expired' => ['Scaduto', 'Expired', 'Abgelaufen', 'Expiré'],
            'greenCare.enum.contractStatus.renewed' => ['Rinnovato', 'Renewed', 'Verlängert', 'Renouvelé'],
            'greenCare.enum.weatherAlert.clear' => ['Sereno', 'Clear', 'Klar', 'Dégagé'],
            'greenCare.enum.weatherAlert.rain_warning' => ['Allerta pioggia', 'Rain warning', 'Regenwarnung', 'Alerte pluie'],
            'greenCare.enum.weatherAlert.frost_alert' => ['Allerta gelo', 'Frost alert', 'Frostwarnung', 'Alerte gel'],
            'greenCare.enum.weatherAlert.rescheduled_due_to_weather' => ['Riprogrammato per il meteo', 'Rescheduled due to weather', 'Wetterbedingt verschoben', 'Reprogrammé à cause de la météo'],
            'greenCare.enum.routeStatus.planned' => ['Pianificato', 'Planned', 'Geplant', 'Planifié'],
            'greenCare.enum.routeStatus.in_progress' => ['In corso', 'In progress', 'In Arbeit', 'En cours'],
            'greenCare.enum.routeStatus.completed' => ['Completato', 'Completed', 'Abgeschlossen', 'Terminé'],
            'greenCare.enum.routeStatus.postponed' => ['Rinviato', 'Postponed', 'Verschoben', 'Reporté'],
            'greenCare.enum.billingStatus.not_billed' => ['Non fatturato', 'Not billed', 'Nicht verrechnet', 'Non facturé'],
            'greenCare.enum.billingStatus.included_in_contract' => ['Incluso nel contratto', 'Included in contract', 'Im Vertrag enthalten', 'Inclus dans le contrat'],
            'greenCare.enum.billingStatus.ready_to_invoice' => ['Da fatturare', 'Ready to invoice', 'Zur Verrechnung bereit', 'Prêt à facturer'],
            'greenCare.enum.billingStatus.invoiced' => ['Fatturato', 'Invoiced', 'Verrechnet', 'Facturé'],
            'greenCare.enum.licenseType.PER' => ['PER (prova di competenza)', 'PER (proof of competence)', 'PER (Nachweis der Sachkunde)', 'PER (preuve de compétence)'],
            'greenCare.enum.licenseType.PNRP' => ['PNRP (autorizzazione speciale)', 'PNRP (special authorisation)', 'PNRP (Sonderbewilligung)', 'PNRP (autorisation spéciale)'],
            'greenCare.enum.licenseType.OPF' => ['OPF (autorizzazione speciale prodotti fitosanitari)', 'OPF (special authorisation for plant protection products)', 'OPF (Sonderbewilligung für Pflanzenschutzmittel)', 'OPF (autorisation spéciale pour produits phytosanitaires)'],
            'greenCare.enum.licenseType.PAN' => ['Patentino fitosanitario PAN', 'PAN plant protection licence', 'PAN-Pflanzenschutzausweis', 'Certificat phytosanitaire PAN'],
            'greenCare.enum.dosageUnit.ml_hl' => ['ml/hl', 'ml/hl', 'ml/hl', 'ml/hl'],
            'greenCare.enum.dosageUnit.g_100sqm' => ['g/100 m²', 'g/100 m²', 'g/100 m²', 'g/100 m²'],
            'greenCare.enum.dosageUnit.l_ha' => ['l/ha', 'l/ha', 'l/ha', 'l/ha'],
            'greenCare.enum.equipment.lawnmowers' => ['Tosaerba', 'Lawnmowers', 'Rasenmäher', 'Tondeuses'],
            'greenCare.enum.equipment.ride_on_mowers' => ['Trattorini tosaerba', 'Ride-on mowers', 'Aufsitzmäher', 'Tondeuses autoportées'],
            'greenCare.enum.equipment.chainsaws' => ['Motoseghe', 'Chainsaws', 'Motorsägen', 'Tronçonneuses'],
            'greenCare.enum.equipment.hedge_trimmers' => ['Tagliasiepi', 'Hedge trimmers', 'Heckenscheren', 'Taille-haies'],
            'greenCare.enum.equipment.sprayers' => ['Irroratrici', 'Sprayers', 'Spritzgeräte', 'Pulvérisateurs'],
            'greenCare.enum.equipment.snow_plows' => ['Lame sgombraneve', 'Snow plows', 'Schneepflüge', 'Lames à neige'],
            'greenCare.enum.service.mowing' => ['Taglio del prato', 'Mowing', 'Rasenmähen', 'Tonte'],
            'greenCare.enum.service.hedging' => ['Potatura siepi', 'Hedge trimming', 'Heckenschnitt', 'Taille des haies'],
            'greenCare.enum.service.fertilization' => ['Concimazione', 'Fertilization', 'Düngung', 'Fertilisation'],
            'greenCare.enum.service.irrigation_startup' => ['Avvio impianto di irrigazione', 'Irrigation start-up', 'Inbetriebnahme der Bewässerung', 'Mise en service de l\'arrosage'],
            'greenCare.enum.service.irrigation_winterization' => ['Svernamento impianto di irrigazione', 'Irrigation winterization', 'Einwinterung der Bewässerung', 'Hivernage de l\'arrosage'],
            'greenCare.enum.service.snow_clearing' => ['Sgombero neve', 'Snow clearing', 'Schneeräumung', 'Déneigement'],
            'greenCare.enum.service.pruning' => ['Potatura', 'Pruning', 'Baumschnitt', 'Élagage'],
            'greenCare.enum.service.phytosanitary' => ['Trattamento fitosanitario', 'Plant protection treatment', 'Pflanzenschutzbehandlung', 'Traitement phytosanitaire'],
            'greenCare.enum.invoiceLine.installment' => ['Rata', 'Installment', 'Rate', 'Acompte'],
            'greenCare.enum.invoiceLine.snow_standby' => ['Reperibilità neve', 'Snow standby', 'Schneebereitschaft', 'Astreinte neige'],
            'greenCare.enum.invoiceLine.extra_passage' => ['Passaggio extra', 'Extra passage', 'Zusätzlicher Einsatz', 'Passage supplémentaire'],
            'greenCare.enum.invoiceLine.time_and_material' => ['Ore a consuntivo', 'Time and material', 'Aufwandstunden', 'Heures en régie'],
            'greenCare.enum.invoiceLine.material' => ['Piante e materiali', 'Plants and materials', 'Pflanzen und Material', 'Plantes et matériaux'],
            'greenCare.enum.invoiceLine.waste' => ['Smaltimento verde', 'Green waste disposal', 'Grünabfallentsorgung', 'Élimination des déchets verts'],
            'greenCare.status.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'greenCare.status.inactive' => ['Non attivo', 'Inactive', 'Inaktiv', 'Inactif'],
            'greenCare.common.yes' => ['Sì', 'Yes', 'Ja', 'Oui'],
            'greenCare.common.no' => ['No', 'No', 'Nein', 'Non'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'greenCare.error.unknown' => ['Operazione non riuscita. Riprova.', 'The operation failed. Try again.', 'Der Vorgang ist fehlgeschlagen. Bitte erneut versuchen.', 'L\'opération a échoué. Réessayez.'],
            'greenCare.error.operator_license_missing' => ['L\'operatore non ha un patentino fitosanitario valido (PER/PNRP/OPF in CH, patentino PAN in IT) alla data del trattamento.', 'The operator holds no valid plant protection licence (PER/PNRP/OPF in CH, PAN licence in IT) on the treatment date.', 'Der Operator hat am Behandlungsdatum keine gültige Pflanzenschutzbewilligung (PER/PNRP/OPF in CH, PAN-Ausweis in IT).', 'L\'opérateur ne détient pas de certificat phytosanitaire valable (PER/PNRP/OPF en CH, PAN en IT) à la date du traitement.'],
            'greenCare.error.operator_license_expired' => ['Il patentino fitosanitario dell\'operatore è scaduto il {expiry_date}: il trattamento non può essere registrato.', 'The operator\'s plant protection licence expired on {expiry_date}: the treatment cannot be recorded.', 'Die Pflanzenschutzbewilligung des Operators ist am {expiry_date} abgelaufen: Die Behandlung kann nicht erfasst werden.', 'Le certificat phytosanitaire de l\'opérateur a expiré le {expiry_date} : le traitement ne peut pas être enregistré.'],
            'greenCare.error.registration_number_invalid' => ['Il numero di registrazione del prodotto non è nel formato richiesto (UFAG/BLW in CH, Ministero della Salute in IT).', 'The product registration number does not have the required format (UFAG/BLW in CH, Ministry of Health in IT).', 'Die Zulassungsnummer des Produkts hat nicht das verlangte Format (BLW in CH, Gesundheitsministerium in IT).', 'Le numéro d\'homologation du produit n\'a pas le format requis (OFAG/BLW en CH, ministère de la Santé en IT).'],
            'greenCare.error.contract_not_active' => ['Il contratto non è attivo.', 'The contract is not active.', 'Der Vertrag ist nicht aktiv.', 'Le contrat n\'est pas actif.'],
            'greenCare.error.contract_not_billable' => ['Il contratto non può essere fatturato in questo stato.', 'The contract cannot be invoiced in this state.', 'Der Vertrag kann in diesem Zustand nicht verrechnet werden.', 'Le contrat ne peut pas être facturé dans cet état.'],
            'greenCare.error.nothing_to_bill' => ['Non c\'è nulla da fatturare nel periodo scelto.', 'There is nothing to invoice in the chosen period.', 'Im gewählten Zeitraum gibt es nichts zu verrechnen.', 'Il n\'y a rien à facturer sur la période choisie.'],
            'greenCare.error.stop_already_completed' => ['La tappa è già stata completata.', 'The stop has already been completed.', 'Der Halt wurde bereits abgeschlossen.', 'L\'arrêt est déjà terminé.'],
            'greenCare.error.route_locked' => ['Il giro è chiuso e non può essere modificato.', 'The route is closed and cannot be changed.', 'Die Route ist abgeschlossen und kann nicht geändert werden.', 'La tournée est clôturée et ne peut plus être modifiée.'],
            'greenCare.error.week_start_invalid' => ['La settimana deve iniziare di lunedì.', 'The week must start on a Monday.', 'Die Woche muss an einem Montag beginnen.', 'La semaine doit commencer un lundi.'],
            'greenCare.error.no_crews_available' => ['Nessuna squadra attiva disponibile.', 'No active crew is available.', 'Kein aktives Team verfügbar.', 'Aucune équipe active n\'est disponible.'],
            'greenCare.error.warehouse_required' => ['Indica il magazzino da cui scaricare il prodotto.', 'Choose the warehouse the product is taken from.', 'Wählen Sie das Lager, aus dem das Produkt entnommen wird.', 'Indiquez l\'entrepôt d\'où le produit est prélevé.'],
            'greenCare.error.condominium_data_missing' => ['Per il condominio mancano il codice fiscale o l\'amministratore: servono per la ritenuta del 4% e per la fattura elettronica.', 'The condominium fiscal code or administrator is missing: they are needed for the 4% withholding and the electronic invoice.', 'Steuernummer oder Verwalter der Eigentümergemeinschaft fehlen: Sie werden für den Quellensteuerabzug von 4% und die elektronische Rechnung benötigt.', 'Le code fiscal ou le syndic de la copropriété manque : ils sont nécessaires pour la retenue de 4% et la facture électronique.'],
            'greenCare.error.weather_unavailable' => ['Le previsioni meteo non sono disponibili al momento.', 'The weather forecast is not available at the moment.', 'Die Wetterprognose ist momentan nicht verfügbar.', 'Les prévisions météo ne sont pas disponibles pour le moment.'],
            'greenCare.error.file_not_found' => ['Il file richiesto non è disponibile.', 'The requested file is not available.', 'Die angeforderte Datei ist nicht verfügbar.', 'Le fichier demandé n\'est pas disponible.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'greenCare.field.code' => ['Codice', 'Code', 'Code', 'Code'],
            'greenCare.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'greenCare.field.customer' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'greenCare.field.condominiumAdministrator' => ['Amministratore di condominio', 'Condominium administrator', 'Verwalter der Eigentümergemeinschaft', 'Syndic de copropriété'],
            'greenCare.field.jurisdiction' => ['Giurisdizione', 'Jurisdiction', 'Rechtsraum', 'Juridiction'],
            'greenCare.field.address' => ['Indirizzo', 'Address', 'Adresse', 'Adresse'],
            'greenCare.field.latitude' => ['Latitudine', 'Latitude', 'Breitengrad', 'Latitude'],
            'greenCare.field.longitude' => ['Longitudine', 'Longitude', 'Längengrad', 'Longitude'],
            'greenCare.field.polygon' => ['Perimetro dell\'area (un vertice per riga)', 'Area boundary (one vertex per line)', 'Umriss der Fläche (ein Eckpunkt pro Zeile)', 'Périmètre de la zone (un sommet par ligne)'],
            'greenCare.field.lawnArea' => ['Superficie prato (m²)', 'Lawn area (m²)', 'Rasenfläche (m²)', 'Surface de gazon (m²)'],
            'greenCare.field.hedgeLength' => ['Lunghezza siepi (m)', 'Hedge length (m)', 'Heckenlänge (m)', 'Longueur des haies (m)'],
            'greenCare.field.treeCount' => ['Numero di alberi', 'Number of trees', 'Anzahl Bäume', 'Nombre d\'arbres'],
            'greenCare.field.zones' => ['Zone di irrigazione', 'Irrigation zones', 'Bewässerungszonen', 'Zones d\'arrosage'],
            'greenCare.field.controllerType' => ['Tipo di centralina', 'Controller type', 'Steuerungstyp', 'Type de programmateur'],
            'greenCare.field.winterized' => ['Impianto svernato', 'System winterized', 'Anlage eingewintert', 'Installation hivernée'],
            'greenCare.field.irrigationNotes' => ['Note sull\'impianto', 'System notes', 'Anlagenotizen', 'Notes sur l\'installation'],
            'greenCare.field.gateCode' => ['Codice di accesso al cancello', 'Gate access code', 'Zugangscode zum Tor', 'Code d\'accès au portail'],
            'greenCare.field.accessNotes' => ['Note di accesso', 'Access notes', 'Zugangshinweise', 'Notes d\'accès'],
            'greenCare.field.species' => ['Specie', 'Species', 'Art', 'Espèce'],
            'greenCare.field.plantingDate' => ['Data di messa a dimora', 'Planting date', 'Pflanzdatum', 'Date de plantation'],
            'greenCare.field.pruningSchedule' => ['Calendario di potatura', 'Pruning schedule', 'Schnittplan', 'Calendrier de taille'],
            'greenCare.field.phytosanitaryNotes' => ['Note fitosanitarie', 'Phytosanitary notes', 'Pflanzenschutzhinweise', 'Notes phytosanitaires'],
            'greenCare.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'greenCare.field.contractNumber' => ['Numero contratto', 'Contract number', 'Vertragsnummer', 'Numéro de contrat'],
            'greenCare.field.site' => ['Sito', 'Site', 'Standort', 'Site'],
            'greenCare.field.season' => ['Stagione', 'Season', 'Saison', 'Saison'],
            'greenCare.field.billingMode' => ['Modalità di fatturazione', 'Billing mode', 'Verrechnungsart', 'Mode de facturation'],
            'greenCare.field.passages' => ['Passaggi', 'Passages', 'Einsätze', 'Passages'],
            'greenCare.field.includedPassages' => ['Passaggi inclusi', 'Included passages', 'Inbegriffene Einsätze', 'Passages inclus'],
            'greenCare.field.extraPassagePrice' => ['Prezzo passaggio extra', 'Extra passage price', 'Preis zusätzlicher Einsatz', 'Prix du passage supplémentaire'],
            'greenCare.field.fixedFee' => ['Canone fisso', 'Fixed fee', 'Fixbetrag', 'Forfait'],
            'greenCare.field.installmentFrequency' => ['Frequenza delle rate', 'Installment frequency', 'Ratenfrequenz', 'Fréquence des acomptes'],
            'greenCare.field.hourlyRate' => ['Tariffa oraria a consuntivo', 'Hourly rate (time and material)', 'Stundenansatz (nach Aufwand)', 'Taux horaire (en régie)'],
            'greenCare.field.snowStandbyFee' => ['Canone di reperibilità neve', 'Snow standby fee', 'Pauschale Schneebereitschaft', 'Forfait d\'astreinte neige'],
            'greenCare.field.snowThreshold' => ['Soglia neve per intervento (cm)', 'Snow trigger threshold (cm)', 'Schneeschwelle für Einsatz (cm)', 'Seuil de neige pour intervention (cm)'],
            'greenCare.field.includedServices' => ['Servizi inclusi', 'Included services', 'Inbegriffene Leistungen', 'Services inclus'],
            'greenCare.field.autoBillExtra' => ['Fattura automaticamente i passaggi extra', 'Automatically bill extra passages', 'Zusätzliche Einsätze automatisch verrechnen', 'Facturer automatiquement les passages supplémentaires'],
            'greenCare.field.isCondominium' => ['Condominio italiano', 'Italian condominium', 'Italienisches Kondominium', 'Copropriété italienne'],
            'greenCare.field.applyWithholding' => ['Applica ritenuta d\'acconto 4%', 'Apply 4% withholding tax', 'Quellensteuerabzug von 4% anwenden', 'Appliquer la retenue à la source de 4%'],
            'greenCare.field.condominiumFiscalCode' => ['Codice fiscale del condominio', 'Condominium fiscal code', 'Steuernummer der Eigentümergemeinschaft', 'Code fiscal de la copropriété'],
            'greenCare.field.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'greenCare.field.unitPrice' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'greenCare.field.vatRate' => ['Aliquota IVA', 'VAT rate', 'MWST-Satz', 'Taux de TVA'],
            'greenCare.field.operator' => ['Operatore', 'Operator', 'Operator', 'Opérateur'],
            'greenCare.field.licenseType' => ['Tipo di patentino', 'Licence type', 'Bewilligungsart', 'Type de certificat'],
            'greenCare.field.expiryDate' => ['Scadenza', 'Expiry date', 'Ablaufdatum', 'Date d\'expiration'],
            'greenCare.field.daysToExpiry' => ['Giorni alla scadenza', 'Days to expiry', 'Tage bis Ablauf', 'Jours avant expiration'],
            'greenCare.field.vehiclePlate' => ['Targa veicolo', 'Vehicle plate', 'Fahrzeugkennzeichen', 'Plaque du véhicule'],
            'greenCare.field.trailerPlate' => ['Targa rimorchio', 'Trailer plate', 'Anhängerkennzeichen', 'Plaque de la remorque'],
            'greenCare.field.equipment' => ['Attrezzatura', 'Equipment', 'Ausrüstung', 'Équipement'],
            'greenCare.field.checkIn' => ['Check-in', 'Check-in', 'Check-in', 'Arrivée'],
            'greenCare.field.checkOut' => ['Check-out', 'Check-out', 'Check-out', 'Départ'],
            'greenCare.field.countsTowardPassage' => ['Conta come passaggio del contratto', 'Counts as a contract passage', 'Zählt als Vertragseinsatz', 'Compte comme passage du contrat'],
            'greenCare.field.weightKg' => ['Peso (kg)', 'Weight (kg)', 'Gewicht (kg)', 'Poids (kg)'],
            'greenCare.field.wasteCode' => ['Codice rifiuto', 'Waste code', 'Abfallcode', 'Code déchet'],
            'greenCare.field.wasteDescription' => ['Descrizione del rifiuto', 'Waste description', 'Abfallbeschreibung', 'Description du déchet'],
            'greenCare.field.compostFacility' => ['Impianto di compostaggio', 'Composting facility', 'Kompostieranlage', 'Installation de compostage'],
            'greenCare.field.slipNumber' => ['Numero formulario', 'Slip number', 'Scheinnummer', 'Numéro de bordereau'],
            'greenCare.field.disposalDate' => ['Data di conferimento', 'Disposal date', 'Entsorgungsdatum', 'Date d\'élimination'],
            'greenCare.field.transporterPlate' => ['Targa del trasportatore', 'Transporter plate', 'Kennzeichen des Transporteurs', 'Plaque du transporteur'],
            'greenCare.field.documentReference' => ['Riferimento documento (FIR / DDT / OTR)', 'Document reference (FIR / DDT / OTR)', 'Dokumentreferenz (FIR / DDT / OTR)', 'Référence du document (FIR / DDT / OTR)'],
            'greenCare.field.disposalCost' => ['Costo di smaltimento', 'Disposal cost', 'Entsorgungskosten', 'Coût d\'élimination'],
            'greenCare.field.billableToCustomer' => ['Addebitabile al cliente', 'Billable to the customer', 'Dem Kunden verrechenbar', 'Facturable au client'],
            'greenCare.field.treatmentDate' => ['Data del trattamento', 'Treatment date', 'Behandlungsdatum', 'Date du traitement'],
            'greenCare.field.product' => ['Prodotto', 'Product', 'Produkt', 'Produit'],
            'greenCare.field.commercialProduct' => ['Nome commerciale del prodotto', 'Commercial product name', 'Handelsname des Produkts', 'Nom commercial du produit'],
            'greenCare.field.registrationNumber' => ['Numero di registrazione', 'Registration number', 'Zulassungsnummer', 'Numéro d\'homologation'],
            'greenCare.field.activeIngredient' => ['Principio attivo', 'Active ingredient', 'Wirkstoff', 'Substance active'],
            'greenCare.field.target' => ['Avversità o infestante', 'Target pest or weed', 'Zielschädling oder Unkraut', 'Ravageur ou adventice ciblé'],
            'greenCare.field.treatedSurface' => ['Coltura o superficie trattata', 'Treated crop or surface', 'Behandelte Kultur oder Fläche', 'Culture ou surface traitée'],
            'greenCare.field.treatedArea' => ['Superficie trattata (m²)', 'Treated area (m²)', 'Behandelte Fläche (m²)', 'Surface traitée (m²)'],
            'greenCare.field.dosage' => ['Dose utilizzata', 'Dosage used', 'Verwendete Dosis', 'Dose utilisée'],
            'greenCare.field.dosageUnit' => ['Unità di dose', 'Dosage unit', 'Dosiseinheit', 'Unité de dose'],
            'greenCare.field.waterVolume' => ['Volume d\'acqua (l)', 'Water volume (l)', 'Wassermenge (l)', 'Volume d\'eau (l)'],
            'greenCare.field.warehouseProduct' => ['Prodotto a magazzino (scarico)', 'Warehouse product (stock deduction)', 'Lagerprodukt (Lagerabgang)', 'Produit en stock (sortie de stock)'],
            'greenCare.field.warehouse' => ['Magazzino', 'Warehouse', 'Lager', 'Entrepôt'],
            'greenCare.field.reEntry' => ['Rientro', 'Re-entry', 'Wiederbetreten', 'Rentrée'],
            'greenCare.field.reEntryHours' => ['Intervallo di rientro (ore)', 'Re-entry interval (hours)', 'Wartezeit bis zum Betreten (Stunden)', 'Délai de rentrée (heures)'],
            'greenCare.field.weatherConditions' => ['Condizioni meteo all\'applicazione', 'Weather conditions at application', 'Wetterbedingungen bei der Anwendung', 'Conditions météo lors de l\'application'],
            'greenCare.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'greenCare.filter.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'greenCare.filter.activeState' => ['Stato attivo', 'Active state', 'Aktivstatus', 'État actif'],
            'greenCare.filter.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'greenCare.filter.crew' => ['Squadra', 'Crew', 'Team', 'Équipe'],
            'greenCare.filter.crews' => ['Squadre da pianificare', 'Crews to plan', 'Zu planende Teams', 'Équipes à planifier'],
            'greenCare.filter.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'greenCare.hint.polygon' => ['Latitudine, longitudine di ogni vertice, uno per riga (minimo tre).', 'Latitude, longitude of each vertex, one per line (at least three).', 'Breiten- und Längengrad jedes Eckpunkts, einer pro Zeile (mindestens drei).', 'Latitude, longitude de chaque sommet, un par ligne (au moins trois).'],
            'greenCare.hint.registration' => ['Formato del numero di registrazione', 'Registration number format', 'Format der Zulassungsnummer', 'Format du numéro d\'homologation'],
            'greenCare.hint.registrationCh' => ['Numero UFAG/BLW', 'UFAG/BLW number', 'BLW-Zulassungsnummer', 'Numéro OFAG/BLW'],
            'greenCare.hint.registrationIt' => ['Numero di registrazione del Ministero della Salute', 'Ministry of Health registration number', 'Registrierungsnummer des italienischen Gesundheitsministeriums', 'Numéro d\'enregistrement du ministère de la Santé'],
            'greenCare.hint.documentReference' => ['Numero del FIR, del DDT o del documento di movimento OTR.', 'Number of the FIR, DDT or OTR movement document.', 'Nummer des FIR, DDT oder OTR-Begleitscheins.', 'Numéro du FIR, du DDT ou du document de mouvement OTR.'],
            'greenCare.badge.condominium' => ['Condominio', 'Condominium', 'Eigentümergemeinschaft', 'Copropriété'],
            'greenCare.irrigation.winterized' => ['Svernato', 'Winterized', 'Eingewintert', 'Hiverné'],
            'greenCare.irrigation.active' => ['In funzione', 'In operation', 'In Betrieb', 'En service'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'greenCare.action.newSite' => ['Nuovo sito', 'New site', 'Neuer Standort', 'Nouveau site'],
            'greenCare.action.editSite' => ['Modifica sito', 'Edit site', 'Standort bearbeiten', 'Modifier le site'],
            'greenCare.action.newContract' => ['Nuovo contratto', 'New contract', 'Neuer Vertrag', 'Nouveau contrat'],
            'greenCare.action.editContract' => ['Modifica contratto', 'Edit contract', 'Vertrag bearbeiten', 'Modifier le contrat'],
            'greenCare.action.newSlip' => ['Nuovo formulario', 'New slip', 'Neuer Schein', 'Nouveau bordereau'],
            'greenCare.action.editSlip' => ['Modifica formulario', 'Edit slip', 'Schein bearbeiten', 'Modifier le bordereau'],
            'greenCare.action.newTreatment' => ['Registra trattamento', 'Record treatment', 'Behandlung erfassen', 'Enregistrer un traitement'],
            'greenCare.action.saveTreatment' => ['Registra nel quaderno', 'Record in the diary', 'Im Feldbuch erfassen', 'Enregistrer dans le cahier'],
            'greenCare.action.exportInspectionPdf' => ['Esporta PDF per ispezione', 'Export inspection PDF', 'Prüf-PDF exportieren', 'Exporter le PDF d\'inspection'],
            'greenCare.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'greenCare.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'greenCare.action.remove' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'greenCare.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'greenCare.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'greenCare.action.close' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
            'greenCare.action.preview' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'greenCare.action.activate' => ['Attiva', 'Activate', 'Aktivieren', 'Activer'],
            'greenCare.action.suspend' => ['Sospendi', 'Suspend', 'Aussetzen', 'Suspendre'],
            'greenCare.action.generateInvoice' => ['Genera fattura', 'Generate invoice', 'Rechnung erstellen', 'Générer la facture'],
            'greenCare.action.addSpecies' => ['Aggiungi specie', 'Add species', 'Art hinzufügen', 'Ajouter une espèce'],
            'greenCare.action.openPlanner' => ['Apri la pianificazione', 'Open the planner', 'Planung öffnen', 'Ouvrir la planification'],
            'greenCare.action.openContracts' => ['Apri i contratti', 'Open the contracts', 'Verträge öffnen', 'Ouvrir les contrats'],
            'greenCare.action.openTreatments' => ['Apri il quaderno di campagna', 'Open the field diary', 'Feldbuch öffnen', 'Ouvrir le cahier de campagne'],
            'greenCare.action.optimizeWeek' => ['Ottimizza giri settimanali', 'Optimize weekly routes', 'Wochenrouten optimieren', 'Optimiser les tournées de la semaine'],
            'greenCare.action.rescheduleRain' => ['Riprogramma tappe con pioggia', 'Auto-reschedule rainy stops', 'Regentage automatisch verschieben', 'Reprogrammer automatiquement les arrêts pluvieux'],
            'greenCare.action.refreshWeather' => ['Aggiorna meteo', 'Refresh weather', 'Wetter aktualisieren', 'Actualiser la météo'],
            'greenCare.action.deleteRoute' => ['Elimina giro', 'Delete route', 'Route löschen', 'Supprimer la tournée'],
            'greenCare.action.logIntervention' => ['Registra intervento', 'Log intervention', 'Einsatz erfassen', 'Enregistrer l\'intervention'],
            'greenCare.action.saveIntervention' => ['Salva intervento', 'Save intervention', 'Einsatz speichern', 'Enregistrer l\'intervention'],
            'greenCare.action.captureGps' => ['Rileva posizione GPS', 'Capture GPS position', 'GPS-Position erfassen', 'Relever la position GPS'],
            'greenCare.action.addMachine' => ['Aggiungi macchina', 'Add machine', 'Maschine hinzufügen', 'Ajouter une machine'],
            'greenCare.action.addMaterial' => ['Aggiungi pianta o materiale', 'Add plant or material', 'Pflanze oder Material hinzufügen', 'Ajouter une plante ou un matériau'],
            'greenCare.action.uploadBefore' => ['Foto prima', 'Photos before', 'Fotos vorher', 'Photos avant'],
            'greenCare.action.uploadAfter' => ['Foto dopo', 'Photos after', 'Fotos nachher', 'Photos après'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'greenCare.section.dashboardSubtitle' => ['Contratti stagionali, passaggi eseguiti, meteo, trattamenti e rifiuti verdi della stagione.', 'Seasonal contracts, executed passages, weather, treatments and green waste of the season.', 'Saisonverträge, ausgeführte Einsätze, Wetter, Behandlungen und Grünabfall der Saison.', 'Contrats saisonniers, passages effectués, météo, traitements et déchets verts de la saison.'],
            'greenCare.section.sitesSubtitle' => ['Giardini e aree verdi in gestione, con superfici, impianti di irrigazione e inventario botanico.', 'Managed gardens and green areas, with surfaces, irrigation systems and botanical inventory.', 'Betreute Gärten und Grünflächen mit Flächen, Bewässerungsanlagen und Pflanzeninventar.', 'Jardins et espaces verts gérés, avec surfaces, installations d\'arrosage et inventaire botanique.'],
            'greenCare.section.contractsSubtitle' => ['Contratti di manutenzione stagionale e condominiali con contapassaggi e fatturazione mista.', 'Seasonal and condominium maintenance contracts with passage counter and mixed invoicing.', 'Saison- und Stockwerkeigentümer-Unterhaltsverträge mit Einsatzzähler und gemischter Verrechnung.', 'Contrats d\'entretien saisonniers et de copropriété avec compteur de passages et facturation mixte.'],
            'greenCare.section.plannerSubtitle' => ['Giri settimanali delle squadre con allerte meteo, ottimizzazione e registro degli interventi.', 'Weekly crew routes with weather alerts, optimisation and intervention log.', 'Wochenrouten der Teams mit Wetterwarnungen, Optimierung und Einsatzprotokoll.', 'Tournées hebdomadaires des équipes avec alertes météo, optimisation et journal des interventions.'],
            'greenCare.section.treatmentsSubtitle' => ['Registro immutabile dei trattamenti fitosanitari, pronto per l\'ispezione.', 'Immutable plant protection treatment register, ready for inspection.', 'Unveränderliches Register der Pflanzenschutzbehandlungen, bereit für die Kontrolle.', 'Registre immuable des traitements phytosanitaires, prêt pour l\'inspection.'],
            'greenCare.section.wasteSubtitle' => ['Formulari di smaltimento del verde con pesi, impianti di compostaggio e addebito al cliente.', 'Green waste disposal slips with weights, composting facilities and customer pass-through.', 'Entsorgungsscheine für Grünabfall mit Gewichten, Kompostieranlagen und Weiterverrechnung an den Kunden.', 'Bordereaux d\'élimination des déchets verts avec poids, installations de compostage et refacturation au client.'],
            'greenCare.section.expiringLicenses' => ['Patentini fitosanitari in scadenza', 'Plant protection licences about to expire', 'Ablaufende Pflanzenschutzbewilligungen', 'Certificats phytosanitaires bientôt expirés'],
            'greenCare.section.geometry' => ['Area e posizione', 'Area and position', 'Fläche und Lage', 'Zone et position'],
            'greenCare.section.irrigation' => ['Impianto di irrigazione', 'Irrigation system', 'Bewässerungsanlage', 'Installation d\'arrosage'],
            'greenCare.section.botanicalInventory' => ['Inventario botanico', 'Botanical inventory', 'Pflanzeninventar', 'Inventaire botanique'],
            'greenCare.kpi.active_contracts' => ['Contratti stagionali attivi', 'Active seasonal contracts', 'Aktive Saisonverträge', 'Contrats saisonniers actifs'],
            'greenCare.kpi.passages' => ['Passaggi eseguiti', 'Passages executed', 'Ausgeführte Einsätze', 'Passages effectués'],
            'greenCare.kpi.passagesOf' => ['{done} eseguiti su {total} contrattualizzati', '{done} executed of {total} contracted', '{done} von {total} vertraglichen Einsätzen ausgeführt', '{done} effectués sur {total} contractuels'],
            'greenCare.kpi.extra_passages_to_invoice' => ['Passaggi extra da fatturare', 'Extra passages to invoice', 'Zusätzliche Einsätze zur Verrechnung', 'Passages supplémentaires à facturer'],
            'greenCare.kpi.weather_impacted_routes' => ['Giri colpiti dal meteo', 'Weather-impacted routes', 'Vom Wetter betroffene Routen', 'Tournées touchées par la météo'],
            'greenCare.kpi.upcomingRoutes' => ['{count} giri in programma', '{count} upcoming routes', '{count} bevorstehende Routen', '{count} tournées à venir'],
            'greenCare.kpi.treatments_logged' => ['Trattamenti fitosanitari registrati', 'Phytosanitary treatments logged', 'Erfasste Pflanzenschutzbehandlungen', 'Traitements phytosanitaires enregistrés'],
            'greenCare.kpi.green_waste_kg' => ['Rifiuti verdi smaltiti (kg)', 'Green waste disposed (kg)', 'Entsorgter Grünabfall (kg)', 'Déchets verts éliminés (kg)'],
            'greenCare.kpi.billableDisposalCost' => ['Costi di smaltimento addebitabili', 'Billable disposal costs', 'Verrechenbare Entsorgungskosten', 'Coûts d\'élimination facturables'],
            'greenCare.empty.licenses' => ['Nessun patentino in scadenza.', 'No licence about to expire.', 'Keine ablaufende Bewilligung.', 'Aucun certificat bientôt expiré.'],
            'greenCare.empty.siteSelection' => ['Seleziona un sito per vederne il dettaglio.', 'Select a site to see its details.', 'Wählen Sie einen Standort, um Details zu sehen.', 'Sélectionnez un site pour en voir le détail.'],
            'greenCare.confirm.deleteSite' => ['Eliminare il sito?', 'Delete the site?', 'Standort löschen?', 'Supprimer le site ?'],
            'greenCare.confirm.deleteContract' => ['Eliminare il contratto?', 'Delete the contract?', 'Vertrag löschen?', 'Supprimer le contrat ?'],
            'greenCare.confirm.deleteSlip' => ['Eliminare il formulario?', 'Delete the slip?', 'Schein löschen?', 'Supprimer le bordereau ?'],
            'greenCare.confirm.deleteRoute' => ['Eliminare il giro e le sue tappe?', 'Delete the route and its stops?', 'Route und ihre Halte löschen?', 'Supprimer la tournée et ses arrêts ?'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function contractsAndBilling(): array
    {
        return [
            'greenCare.passage.within' => ['Passaggio {current} di {included}', 'Passage {current} of {included}', 'Einsatz {current} von {included}', 'Passage {current} sur {included}'],
            'greenCare.passage.extra' => ['{current} di {included} — extra da fatturare', '{current} of {included} — extra to invoice', '{current} von {included} — Zusatzeinsatz zu verrechnen', '{current} sur {included} — supplément à facturer'],
            'greenCare.passage.extraCount' => ['{extra} passaggi extra', '{extra} extra passages', '{extra} zusätzliche Einsätze', '{extra} passages supplémentaires'],
            'greenCare.billing.title' => ['Genera fattura del contratto', 'Generate the contract invoice', 'Vertragsrechnung erstellen', 'Générer la facture du contrat'],
            'greenCare.billing.period' => ['Periodo da fatturare', 'Period to invoice', 'Zu verrechnender Zeitraum', 'Période à facturer'],
            'greenCare.billing.installment' => ['Rata del canone fisso', 'Fixed fee installment', 'Rate des Fixbetrags', 'Acompte du forfait'],
            'greenCare.billing.snowStandby' => ['Reperibilità neve invernale', 'Winter snow standby', 'Winterliche Schneebereitschaft', 'Astreinte neige hivernale'],
            'greenCare.billing.extraPassages' => ['Passaggi extra oltre il contratto', 'Extra passages beyond the contract', 'Zusätzliche Einsätze über den Vertrag hinaus', 'Passages supplémentaires au-delà du contrat'],
            'greenCare.billing.timeAndMaterial' => ['Ore a consuntivo, piante e materiali', 'Time and material, plants and materials', 'Aufwandstunden, Pflanzen und Material', 'Heures en régie, plantes et matériaux'],
            'greenCare.billing.waste' => ['Smaltimento del verde addebitabile', 'Billable green waste disposal', 'Verrechenbare Grünabfallentsorgung', 'Élimination des déchets verts facturable'],
            'greenCare.billing.line' => ['Voce', 'Line', 'Position', 'Ligne'],
            'greenCare.billing.net' => ['Imponibile', 'Net amount', 'Nettobetrag', 'Montant net'],
            'greenCare.billing.totalNet' => ['Totale imponibile', 'Total net', 'Total netto', 'Total net'],
            'greenCare.billing.totalVat' => ['IVA', 'VAT', 'MWST', 'TVA'],
            'greenCare.billing.withholding' => ['Ritenuta d\'acconto {percent}%', 'Withholding tax {percent}%', 'Quellensteuerabzug {percent}%', 'Retenue à la source {percent}%'],
            'greenCare.billing.netPayable' => ['Netto a pagare', 'Net payable', 'Netto zahlbar', 'Net à payer'],
            'greenCare.billing.withholdingHint' => ['Condominio italiano: nella fattura verrà applicata la ritenuta d\'acconto del {percent}% (Art. 25-ter DPR 600/73). Genera l\'anteprima per vedere gli importi.', 'Italian condominium: the {percent}% withholding tax (Art. 25-ter DPR 600/73) will be applied to the invoice. Run the preview to see the amounts.', 'Italienisches Kondominium: Auf die Rechnung wird der Quellensteuerabzug von {percent}% (Art. 25-ter DPR 600/73) angewendet. Erstellen Sie die Vorschau, um die Beträge zu sehen.', 'Copropriété italienne : la retenue à la source de {percent}% (art. 25-ter DPR 600/73) sera appliquée à la facture. Lancez l\'aperçu pour voir les montants.'],
            'greenCare.billing.withholdingNote' => ['Ritenuta nella fattura elettronica: tipo {type}, causale {reason}.', 'Withholding in the electronic invoice: type {type}, reason {reason}.', 'Quellensteuerabzug in der elektronischen Rechnung: Typ {type}, Grund {reason}.', 'Retenue dans la facture électronique : type {type}, motif {reason}.'],
            'greenCare.billing.condominiumFiscalCode' => ['Codice fiscale condominio', 'Condominium fiscal code', 'Steuernummer der Eigentümergemeinschaft', 'Code fiscal de la copropriété'],
            'greenCare.billing.invoiceCreated' => ['La fattura è stata creata in bozza nel modulo di fatturazione.', 'The invoice was created as a draft in the invoicing module.', 'Die Rechnung wurde als Entwurf im Fakturierungsmodul erstellt.', 'La facture a été créée en brouillon dans le module de facturation.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function planner(): array
    {
        return [
            'greenCare.planner.previousWeek' => ['Settimana precedente', 'Previous week', 'Vorwoche', 'Semaine précédente'],
            'greenCare.planner.thisWeek' => ['Questa settimana', 'This week', 'Diese Woche', 'Cette semaine'],
            'greenCare.planner.nextWeek' => ['Settimana successiva', 'Next week', 'Nächste Woche', 'Semaine suivante'],
            'greenCare.planner.stopsCount' => ['{count} tappe', '{count} stops', '{count} Halte', '{count} arrêts'],
            'greenCare.planner.routeDetail' => ['Dettaglio del giro', 'Route detail', 'Routendetail', 'Détail de la tournée'],
            'greenCare.planner.stops' => ['Tappe in ordine', 'Ordered stops', 'Halte in Reihenfolge', 'Arrêts dans l\'ordre'],
            'greenCare.planner.done' => ['Completata', 'Done', 'Erledigt', 'Terminé'],
            'greenCare.planner.forecast' => ['Previsioni: pioggia {rain} mm, temperatura {min}–{max} °C, vento {wind} km/h', 'Forecast: rain {rain} mm, temperature {min}–{max} °C, wind {wind} km/h', 'Prognose: Regen {rain} mm, Temperatur {min}–{max} °C, Wind {wind} km/h', 'Prévisions : pluie {rain} mm, température {min}–{max} °C, vent {wind} km/h'],
            'greenCare.planner.optimizeTitle' => ['Ottimizza i giri settimanali', 'Optimize the weekly routes', 'Wochenrouten optimieren', 'Optimiser les tournées de la semaine'],
            'greenCare.planner.optimizeIntro' => ['Raggruppa gli interventi dovuti dei contratti attivi per vicinanza geografica e capacità della squadra nella settimana {week}.', 'Groups the due interventions of the active contracts by geographic proximity and crew capacity for the week {week}.', 'Gruppiert die fälligen Einsätze der aktiven Verträge nach geografischer Nähe und Teamkapazität für die Woche {week}.', 'Regroupe les interventions dues des contrats actifs par proximité géographique et capacité des équipes pour la semaine {week}.'],
            'greenCare.planner.refreshWeather' => ['Aggiorna le previsioni meteo dei giri', 'Refresh the weather forecast of the routes', 'Wetterprognose der Routen aktualisieren', 'Actualiser les prévisions météo des tournées'],
            'greenCare.planner.optimized' => ['Creati {routes} giri e {stops} tappe.', 'Created {routes} routes and {stops} stops.', '{routes} Routen und {stops} Halte erstellt.', '{routes} tournées et {stops} arrêts créés.'],
            'greenCare.planner.unassigned' => ['Interventi non assegnati', 'Unassigned interventions', 'Nicht zugewiesene Einsätze', 'Interventions non affectées'],
            'greenCare.planner.rescheduled' => ['{stops} tappe riprogrammate su {routes} giri per il meteo.', '{stops} stops rescheduled on {routes} routes because of the weather.', '{stops} Halte auf {routes} Routen wegen des Wetters verschoben.', '{stops} arrêts reprogrammés sur {routes} tournées à cause de la météo.'],
            'greenCare.empty.dayRoutes' => ['Nessun giro', 'No routes', 'Keine Routen', 'Aucune tournée'],
            'greenCare.empty.stops' => ['Nessuna tappa nel giro.', 'No stops in the route.', 'Keine Halte in der Route.', 'Aucun arrêt dans la tournée.'],
            'greenCare.empty.members' => ['La squadra non ha membri.', 'The crew has no members.', 'Das Team hat keine Mitglieder.', 'L\'équipe n\'a aucun membre.'],
            'greenCare.intervention.title' => ['Registro dell\'intervento', 'Intervention log', 'Einsatzprotokoll', 'Journal de l\'intervention'],
            'greenCare.intervention.crewHours' => ['Ore della squadra', 'Crew hours', 'Stunden des Teams', 'Heures de l\'équipe'],
            'greenCare.intervention.machineryHours' => ['Ore macchina', 'Machinery hours', 'Maschinenstunden', 'Heures machine'],
            'greenCare.intervention.materials' => ['Piante, terriccio e materiali usati', 'Plants, soil and materials used', 'Verwendete Pflanzen, Erde und Material', 'Plantes, terreau et matériaux utilisés'],
            'greenCare.intervention.greenWaste' => ['Rifiuti verdi', 'Green waste', 'Grünabfall', 'Déchets verts'],
            'greenCare.intervention.photos' => ['Foto prima e dopo', 'Before and after photos', 'Fotos vorher und nachher', 'Photos avant et après'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function treatmentsAndWaste(): array
    {
        return [
            'greenCare.license.valid' => ['Patentino valido: {type} n. {number}, scade il {expiry_date}.', 'Valid licence: {type} no. {number}, expires on {expiry_date}.', 'Gültige Bewilligung: {type} Nr. {number}, läuft am {expiry_date} ab.', 'Certificat valable : {type} n° {number}, expire le {expiry_date}.'],
            'greenCare.license.expiring' => ['Patentino in scadenza tra {days} giorni: {type} n. {number}, scade il {expiry_date}.', 'Licence expiring in {days} days: {type} no. {number}, expires on {expiry_date}.', 'Bewilligung läuft in {days} Tagen ab: {type} Nr. {number}, Ablauf am {expiry_date}.', 'Certificat expirant dans {days} jours : {type} n° {number}, expire le {expiry_date}.'],
            'greenCare.treatment.reEntryUntil' => ['Non rientrare nell\'area trattata fino al {until}.', 'Do not re-enter the treated area before {until}.', 'Die behandelte Fläche nicht vor {until} betreten.', 'Ne pas pénétrer dans la zone traitée avant le {until}.'],
            'greenCare.treatment.immutableNote' => ['Il trattamento registrato non può essere modificato né eliminato: il registro è a prova di ispezione.', 'A recorded treatment cannot be changed or deleted: the register is inspection-proof.', 'Eine erfasste Behandlung kann weder geändert noch gelöscht werden: Das Register ist kontrollsicher.', 'Un traitement enregistré ne peut être ni modifié ni supprimé : le registre est prêt pour l\'inspection.'],
            'greenCare.waste.slipsCount' => ['{count} formulari', '{count} slips', '{count} Scheine', '{count} bordereaux'],
            'greenCare.waste.slips' => ['Formulari', 'Slips', 'Scheine', 'Bordereaux'],
            'greenCare.waste.byFacility' => ['Per impianto di compostaggio', 'By composting facility', 'Nach Kompostieranlage', 'Par installation de compostage'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function notifications(): array
    {
        return [
            'greenCare.toast.siteSaved' => ['Sito salvato.', 'Site saved.', 'Standort gespeichert.', 'Site enregistré.'],
            'greenCare.toast.siteDeleted' => ['Sito eliminato.', 'Site deleted.', 'Standort gelöscht.', 'Site supprimé.'],
            'greenCare.toast.contractSaved' => ['Contratto salvato.', 'Contract saved.', 'Vertrag gespeichert.', 'Contrat enregistré.'],
            'greenCare.toast.contractActivated' => ['Contratto attivato.', 'Contract activated.', 'Vertrag aktiviert.', 'Contrat activé.'],
            'greenCare.toast.contractSuspended' => ['Contratto sospeso.', 'Contract suspended.', 'Vertrag ausgesetzt.', 'Contrat suspendu.'],
            'greenCare.toast.contractDeleted' => ['Contratto eliminato.', 'Contract deleted.', 'Vertrag gelöscht.', 'Contrat supprimé.'],
            'greenCare.toast.invoiceGenerated' => ['Fattura generata.', 'Invoice generated.', 'Rechnung erstellt.', 'Facture générée.'],
            'greenCare.toast.routesOptimized' => ['Giri settimanali ottimizzati.', 'Weekly routes optimized.', 'Wochenrouten optimiert.', 'Tournées de la semaine optimisées.'],
            'greenCare.toast.routeDeleted' => ['Giro eliminato.', 'Route deleted.', 'Route gelöscht.', 'Tournée supprimée.'],
            'greenCare.toast.interventionSaved' => ['Intervento registrato.', 'Intervention logged.', 'Einsatz erfasst.', 'Intervention enregistrée.'],
            'greenCare.toast.photosSaved' => ['Foto caricate.', 'Photos uploaded.', 'Fotos hochgeladen.', 'Photos téléversées.'],
            'greenCare.toast.gpsUnavailable' => ['Posizione GPS non disponibile.', 'GPS position not available.', 'GPS-Position nicht verfügbar.', 'Position GPS indisponible.'],
            'greenCare.toast.treatmentSaved' => ['Trattamento registrato nel quaderno di campagna.', 'Treatment recorded in the field diary.', 'Behandlung im Feldbuch erfasst.', 'Traitement enregistré dans le cahier de campagne.'],
            'greenCare.toast.slipSaved' => ['Formulario salvato.', 'Slip saved.', 'Schein gespeichert.', 'Bordereau enregistré.'],
            'greenCare.toast.slipDeleted' => ['Formulario eliminato.', 'Slip deleted.', 'Schein gelöscht.', 'Bordereau supprimé.'],
            'greenCare.error.contract_invalid_transition' => ['Il contratto non può passare a questo stato dallo stato attuale.', 'The contract cannot move to this status from its current status.', 'Der Vertrag kann vom aktuellen Status nicht in diesen Status wechseln.', 'Le contrat ne peut pas passer à ce statut depuis son statut actuel.'],
            'greenCare.unassignedReason.no_coordinates' => ['Il sito non ha coordinate', 'The site has no coordinates', 'Der Standort hat keine Koordinaten', 'Le site n\'a pas de coordonnées'],
            'greenCare.unassignedReason.no_capacity' => ['Nessuna squadra ha capacità sufficiente', 'No crew has enough capacity', 'Kein Team hat ausreichend Kapazität', 'Aucune équipe n\'a assez de capacité'],
            'greenCare.unassignedReason.no_equipped_crew' => ['Nessuna squadra dispone dell\'attrezzatura necessaria', 'No crew has the required equipment', 'Kein Team verfügt über die nötige Ausrüstung', 'Aucune équipe ne dispose de l\'équipement requis'],
            'greenCare.field.dailyCapacity' => ['Capacità giornaliera (minuti)', 'Daily capacity (minutes)', 'Tageskapazität (Minuten)', 'Capacité journalière (minutes)'],
            'greenCare.field.members' => ['Membri', 'Members', 'Mitglieder', 'Membres'],
            'greenCare.field.role' => ['Ruolo', 'Role', 'Rolle', 'Rôle'],
            'greenCare.field.depotLatitude' => ['Latitudine del deposito', 'Depot latitude', 'Breitengrad des Depots', 'Latitude du dépôt'],
            'greenCare.field.depotLongitude' => ['Longitudine del deposito', 'Depot longitude', 'Längengrad des Depots', 'Longitude du dépôt'],
            'greenCare.field.licenseNumber' => ['Numero del patentino', 'Licence number', 'Bewilligungsnummer', 'Numéro du certificat'],
            'greenCare.field.issuingAuthority' => ['Ente di rilascio', 'Issuing authority', 'Ausstellende Behörde', 'Autorité émettrice'],
            'greenCare.field.issueDate' => ['Data di rilascio', 'Issue date', 'Ausstellungsdatum', 'Date de délivrance'],
            'greenCare.field.validity' => ['Validità', 'Validity', 'Gültigkeit', 'Validité'],
            'greenCare.action.newCrew' => ['Nuova squadra', 'New crew', 'Neues Team', 'Nouvelle équipe'],
            'greenCare.action.editCrew' => ['Modifica squadra', 'Edit crew', 'Team bearbeiten', 'Modifier l\'équipe'],
            'greenCare.action.view' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'greenCare.action.addMember' => ['Aggiungi membro', 'Add member', 'Mitglied hinzufügen', 'Ajouter un membre'],
            'greenCare.action.addLicense' => ['Aggiungi patentino', 'Add licence', 'Bewilligung hinzufügen', 'Ajouter un certificat'],
            'greenCare.action.editLicense' => ['Modifica patentino', 'Edit licence', 'Bewilligung bearbeiten', 'Modifier le certificat'],
            'greenCare.confirm.deleteCrew' => ['Eliminare la squadra?', 'Delete the crew?', 'Team löschen?', 'Supprimer l\'équipe ?'],
            'greenCare.confirm.deleteLicense' => ['Eliminare il patentino?', 'Delete the licence?', 'Bewilligung löschen?', 'Supprimer le certificat ?'],
            'greenCare.section.crewsSubtitle' => ['Squadre di lavoro, membri e patentini fitosanitari degli operatori.', 'Work crews, members and plant protection licences of the operators.', 'Arbeitsteams, Mitglieder und Pflanzenschutzbewilligungen der Operatoren.', 'Équipes de travail, membres et certificats phytosanitaires des opérateurs.'],
            'greenCare.section.licenses' => ['Patentini fitosanitari', 'Plant protection licences', 'Pflanzenschutzbewilligungen', 'Certificats phytosanitaires'],
            'greenCare.licenseState.valid' => ['Valido', 'Valid', 'Gültig', 'Valable'],
            'greenCare.licenseState.expiring' => ['In scadenza ({days} g)', 'Expiring ({days} d)', 'Läuft ab ({days} T)', 'Bientôt expiré ({days} j)'],
            'greenCare.licenseState.expired' => ['Scaduto', 'Expired', 'Abgelaufen', 'Expiré'],
            'greenCare.hint.saveMemberFirst' => ['Salva la squadra per aggiungere i patentini a questo membro.', 'Save the crew to add licences to this member.', 'Speichern Sie das Team, um Bewilligungen für dieses Mitglied hinzuzufügen.', 'Enregistrez l\'équipe pour ajouter des certificats à ce membre.'],
            'greenCare.toast.crewSaved' => ['Squadra salvata.', 'Crew saved.', 'Team gespeichert.', 'Équipe enregistrée.'],
            'greenCare.toast.crewDeleted' => ['Squadra eliminata.', 'Crew deleted.', 'Team gelöscht.', 'Équipe supprimée.'],
            'greenCare.toast.licenseSaved' => ['Patentino salvato.', 'Licence saved.', 'Bewilligung gespeichert.', 'Certificat enregistré.'],
            'greenCare.toast.licenseDeleted' => ['Patentino eliminato.', 'Licence deleted.', 'Bewilligung gelöscht.', 'Certificat supprimé.'],
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
