<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the industrial laundry module (Mod 177): par stock and circulating stock per customer, workwear lockers, the PPE
 * wash cycle limit, dormant garments, the RFID roll cage scan console, the UNI EN 14065 RABC certificates, the monthly wash and
 * rental billing, and the codes the API reports (industrialLaundry.error.<code>, .finding.<code>, .rejection.<reason>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal and technical acronyms (RFID, UHF, EPC, DDT, DPI/PPE, RABC, TD24, QR-bill, CFU) stay untranslated.
 */
final class IndustrialLaundryFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::programs(),
            self::findings(),
            self::rejections(),
            self::errors(),
            self::fields(),
            self::actions(),
            self::sections(),
            self::hints(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.INDUSTRIAL_LAUNDRY' => ['Lavanderia industriale', 'Industrial laundry', 'Industriewäscherei', 'Blanchisserie industrielle'],
            'NAV.INDUSTRIAL_LAUNDRY_PAR_STOCK' => ['Dotazioni e stock', 'Par stock', 'Sollbestand und Umlauf', 'Dotations et stock'],
            'NAV.INDUSTRIAL_LAUNDRY_LOCKERS' => ['Armadietti e dipendenti', 'Lockers and employees', 'Spinde und Mitarbeitende', 'Casiers et employés'],
            'NAV.INDUSTRIAL_LAUNDRY_PPE' => ['Limite lavaggi DPI', 'PPE wash limit', 'PSA-Waschlimit', 'Limite de lavages EPI'],
            'NAV.INDUSTRIAL_LAUNDRY_DORMANT' => ['Capi dormienti', 'Dormant garments', 'Ruhende Teile', 'Vêtements dormants'],
            'NAV.INDUSTRIAL_LAUNDRY_RABC' => ['RABC e varchi RFID', 'RABC and RFID gates', 'RABC und RFID-Tore', 'RABC et portiques RFID'],
            'NAV.INDUSTRIAL_LAUNDRY_BILLING' => ['Fatturazione mensile', 'Monthly billing', 'Monatsabrechnung', 'Facturation mensuelle'],
            'PAGE_TITLE.INDUSTRIAL_LAUNDRY_PAR_STOCK' => ['Dotazioni fisse e stock in circolazione per cliente', 'Par level and circulating stock per customer', 'Sollbestand und umlaufender Bestand pro Kunde', 'Dotations fixes et stock en circulation par client'],
            'PAGE_TITLE.INDUSTRIAL_LAUNDRY_LOCKERS' => ['Divise nominali: capo, dipendente e armadietto', 'Personal workwear: garment, employee and locker', 'Persönliche Arbeitskleidung: Teil, Mitarbeitende und Spind', 'Vêtements nominatifs : vêtement, employé et casier'],
            'PAGE_TITLE.INDUSTRIAL_LAUNDRY_PPE' => ['Monitor del limite di lavaggi dei DPI', 'PPE wash cycle limit monitor', 'Monitor des PSA-Waschlimits', 'Suivi de la limite de lavages des EPI'],
            'PAGE_TITLE.INDUSTRIAL_LAUNDRY_DORMANT' => ['Capi dormienti presso il cliente', 'Dormant garments at the customer', 'Ruhende Teile beim Kunden', 'Vêtements dormants chez le client'],
            'PAGE_TITLE.INDUSTRIAL_LAUNDRY_RABC' => ['Certificazione igienica UNI EN 14065 (RABC) e console varchi RFID', 'UNI EN 14065 (RABC) hygiene certification and RFID gate console', 'Hygienezertifizierung UNI EN 14065 (RABC) und RFID-Tor-Konsole', 'Certification d’hygiène UNI EN 14065 (RABC) et console des portiques RFID'],
            'PAGE_TITLE.INDUSTRIAL_LAUNDRY_BILLING' => ['Fatturazione mensile di lavaggio e noleggio', 'Monthly wash and rental billing', 'Monatliche Abrechnung von Wäsche und Miete', 'Facturation mensuelle du lavage et de la location'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'industrialLaundry.articleType.flat_linen' => ['Biancheria piana (lenzuola, tovaglie)', 'Flat linen (sheets, tablecloths)', 'Flachwäsche (Bettwäsche, Tischdecken)', 'Linge plat (draps, nappes)'],
            'industrialLaundry.articleType.terry_towel' => ['Spugna', 'Terry towels', 'Frottee', 'Éponge'],
            'industrialLaundry.articleType.hospital_scrub' => ['Tessile sanitario', 'Healthcare textiles', 'Medizintextilien', 'Textiles de santé'],
            'industrialLaundry.articleType.personal_workwear_uniform' => ['Divisa di lavoro nominale', 'Personal workwear', 'Persönliche Arbeitskleidung', 'Vêtements de travail nominatifs'],
            'industrialLaundry.articleType.ppe_safety_garment' => ['Capo DPI (alta visibilità, ignifugo, antiacido)', 'PPE safety garment (high visibility, flame retardant, acid proof)', 'PSA-Kleidung (Warnschutz, schwer entflammbar, säurefest)', 'Vêtement EPI (haute visibilité, ignifuge, antiacide)'],
            'industrialLaundry.lifecycleState.clean_in_stock' => ['Pulito a magazzino', 'Clean in stock', 'Sauber im Lager', 'Propre en stock'],
            'industrialLaundry.lifecycleState.delivered_to_customer' => ['Consegnato al cliente', 'Delivered to the customer', 'Beim Kunden', 'Livré au client'],
            'industrialLaundry.lifecycleState.soiled_returned' => ['Sporco rientrato', 'Soiled and returned', 'Schmutzig zurück', 'Sale rentré'],
            'industrialLaundry.lifecycleState.in_wash_tunnel' => ['In tunnel di lavaggio', 'In the wash tunnel', 'In der Waschstraße', 'Dans le tunnel de lavage'],
            'industrialLaundry.lifecycleState.in_mending' => ['In rammendo', 'In mending', 'In der Reparatur', 'En réparation'],
            'industrialLaundry.lifecycleState.condemned_rag' => ['Condannato a straccio', 'Condemned as a rag', 'Zum Putzlappen ausgesondert', 'Réformé en chiffon'],
            'industrialLaundry.scanDirection.inbound_soil' => ['Ingresso sporco', 'Soiled inbound', 'Eingang schmutzig', 'Entrée sale'],
            'industrialLaundry.scanDirection.outbound_clean' => ['Uscita pulito', 'Clean outbound', 'Ausgang sauber', 'Sortie propre'],
            'industrialLaundry.billingModel.per_piece_washed' => ['A capo lavato', 'Per piece washed', 'Pro gewaschenes Teil', 'Par pièce lavée'],
            'industrialLaundry.billingModel.per_kg_dirty_or_clean' => ['A kg (sporco o pulito)', 'Per kg (soiled or clean)', 'Pro kg (schmutzig oder sauber)', 'Au kg (sale ou propre)'],
            'industrialLaundry.billingModel.fixed_monthly_rental_per_garment' => ['Noleggio mensile fisso a capo', 'Fixed monthly rental per garment', 'Fixe Monatsmiete pro Teil', 'Location mensuelle fixe par vêtement'],
            'industrialLaundry.billingModel.hybrid_rental_plus_wash' => ['Noleggio più lavaggio', 'Rental plus wash', 'Miete plus Wäsche', 'Location plus lavage'],
            'industrialLaundry.customerSegment.hotel' => ['Hotel', 'Hotel', 'Hotel', 'Hôtel'],
            'industrialLaundry.customerSegment.restaurant' => ['Ristorante', 'Restaurant', 'Restaurant', 'Restaurant'],
            'industrialLaundry.customerSegment.clinic' => ['Clinica', 'Clinic', 'Klinik', 'Clinique'],
            'industrialLaundry.customerSegment.rsa' => ['RSA', 'Nursing home (RSA)', 'Pflegeheim (RSA)', 'EMS (RSA)'],
            'industrialLaundry.customerSegment.industry' => ['Industria', 'Industry', 'Industrie', 'Industrie'],
            'industrialLaundry.rabcRiskZone.standard' => ['Standard', 'Standard', 'Standard', 'Standard'],
            'industrialLaundry.rabcRiskZone.contaminated' => ['Contaminato (rischio infettivo)', 'Contaminated (infectious risk)', 'Kontaminiert (Infektionsrisiko)', 'Contaminé (risque infectieux)'],
            'industrialLaundry.rabcRiskZone.protected' => ['Protetto (reparto sterile)', 'Protected (sterile ward)', 'Geschützt (steriler Bereich)', 'Protégé (service stérile)'],
            'industrialLaundry.certificationStatus.pending' => ['In attesa', 'Pending', 'Ausstehend', 'En attente'],
            'industrialLaundry.certificationStatus.compliant' => ['Conforme', 'Compliant', 'Konform', 'Conforme'],
            'industrialLaundry.certificationStatus.non_compliant' => ['Non conforme', 'Non-compliant', 'Nicht konform', 'Non conforme'],
            'industrialLaundry.billingLineKind.wash_piece' => ['Lavaggio a capo', 'Wash per piece', 'Wäsche pro Teil', 'Lavage à la pièce'],
            'industrialLaundry.billingLineKind.wash_kg' => ['Lavaggio a peso', 'Wash by weight', 'Wäsche nach Gewicht', 'Lavage au poids'],
            'industrialLaundry.billingLineKind.minimum_top_up' => ['Integrazione al minimo garantito', 'Guaranteed minimum top-up', 'Aufstockung auf das garantierte Minimum', 'Complément au minimum garanti'],
            'industrialLaundry.billingLineKind.rental' => ['Noleggio', 'Rental', 'Miete', 'Location'],
            'industrialLaundry.billingLineKind.unreturned' => ['Capi non restituiti (valore di rimpiazzo)', 'Unreturned garments (replacement value)', 'Nicht zurückgegebene Teile (Wiederbeschaffungswert)', 'Vêtements non restitués (valeur de remplacement)'],
            'industrialLaundry.billingRunStatus.drafted' => ['Calcolata', 'Calculated', 'Berechnet', 'Calculée'],
            'industrialLaundry.billingRunStatus.invoiced' => ['Fatturata', 'Invoiced', 'Fakturiert', 'Facturée'],
            'industrialLaundry.ppeStatus.limit_reached' => ['Limite raggiunto', 'Limit reached', 'Limit erreicht', 'Limite atteinte'],
            'industrialLaundry.ppeStatus.near_limit' => ['Vicino al limite', 'Near the limit', 'Nahe am Limit', 'Proche de la limite'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function programs(): array
    {
        return [
            'industrialLaundry.program.STANDARD_60' => ['Standard 60 °C', 'Standard 60 °C', 'Standard 60 °C', 'Standard 60 °C'],
            'industrialLaundry.program.THERMAL_71_3MIN' => ['Termico 71 °C per 3 minuti', 'Thermal 71 °C for 3 minutes', 'Thermisch 71 °C für 3 Minuten', 'Thermique 71 °C pendant 3 minutes'],
            'industrialLaundry.program.THERMAL_85_15MIN' => ['Termico 85 °C per 15 minuti', 'Thermal 85 °C for 15 minutes', 'Thermisch 85 °C für 15 Minuten', 'Thermique 85 °C pendant 15 minutes'],
            'industrialLaundry.program.CHEMOTHERMAL_60_PAA' => ['Chimico-termico 60 °C con acido peracetico', 'Chemo-thermal 60 °C with peracetic acid', 'Chemothermisch 60 °C mit Peressigsäure', 'Chimio-thermique 60 °C à l’acide peracétique'],
            'industrialLaundry.program.CHEMICAL_40_PAA' => ['Chimico 40 °C con acido peracetico', 'Chemical 40 °C with peracetic acid', 'Chemisch 40 °C mit Peressigsäure', 'Chimique 40 °C à l’acide peracétique'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function findings(): array
    {
        return [
            'industrialLaundry.finding.ppe_wash_limit_reached' => ['Il capo :epc ha raggiunto :cycles lavaggi su :limit certificati (:standard): non può essere riconsegnato.', 'Garment :epc reached :cycles of :limit certified washes (:standard): it cannot be issued again.', 'Das Teil :epc hat :cycles von :limit zertifizierten Wäschen erreicht (:standard): es darf nicht erneut ausgegeben werden.', 'Le vêtement :epc a atteint :cycles lavages sur :limit certifiés (:standard) : il ne peut plus être remis.'],
            'industrialLaundry.finding.ppe_wash_limit_near' => ['Il capo :epc è a :cycles lavaggi su :limit: ne restano :remaining.', 'Garment :epc is at :cycles of :limit washes: :remaining left.', 'Das Teil :epc steht bei :cycles von :limit Wäschen: noch :remaining übrig.', 'Le vêtement :epc en est à :cycles lavages sur :limit : il en reste :remaining.'],
            'industrialLaundry.finding.rabc_program_not_disinfecting' => ['Il programma :program non disinfetta, ma la zona di rischio :zone lo richiede.', 'Program :program does not disinfect, but the risk zone :zone requires it.', 'Das Programm :program desinfiziert nicht, die Risikozone :zone verlangt es aber.', 'Le programme :program ne désinfecte pas, mais la zone de risque :zone l’exige.'],
            'industrialLaundry.finding.rabc_temperature_below_target' => ['Temperatura misurata :measured °C sotto l’obiettivo di :target °C.', 'Measured temperature :measured °C is below the target of :target °C.', 'Gemessene Temperatur :measured °C liegt unter dem Ziel von :target °C.', 'Température mesurée :measured °C inférieure à l’objectif de :target °C.'],
            'industrialLaundry.finding.rabc_hold_time_below_target' => ['Tempo di mantenimento :measured min sotto l’obiettivo di :target min.', 'Hold time :measured min is below the target of :target min.', 'Haltezeit :measured min liegt unter dem Ziel von :target min.', 'Temps de maintien :measured min inférieur à l’objectif de :target min.'],
            'industrialLaundry.finding.rabc_chemical_dosing_below_target' => ['Dosaggio chimico :measured ml/kg sotto l’obiettivo di :target ml/kg.', 'Chemical dosing :measured ml/kg is below the target of :target ml/kg.', 'Chemiedosierung :measured ml/kg liegt unter dem Ziel von :target ml/kg.', 'Dosage chimique :measured ml/kg inférieur à l’objectif de :target ml/kg.'],
            'industrialLaundry.finding.rabc_microbial_count_exceeded' => ['Carica microbica :measured UFC/25 cm² sopra il limite di :limit.', 'Microbial count :measured CFU/25 cm² is above the limit of :limit.', 'Keimzahl :measured KBE/25 cm² liegt über dem Grenzwert von :limit.', 'Charge microbienne :measured UFC/25 cm² supérieure à la limite de :limit.'],
            'industrialLaundry.finding.customer_unresolved' => ['Il cliente non è stato riconosciuto: il DDT non è stato generato.', 'The customer was not recognised: the delivery note was not generated.', 'Der Kunde wurde nicht erkannt: der Lieferschein wurde nicht erstellt.', 'Le client n’a pas été reconnu : le bon de livraison n’a pas été généré.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function rejections(): array
    {
        return [
            'industrialLaundry.rejection.invalid_epc' => ['EPC non valido (lettura spuria)', 'Invalid EPC (spurious read)', 'Ungültiger EPC (Fehllesung)', 'EPC invalide (lecture parasite)'],
            'industrialLaundry.rejection.foreign_antenna' => ['Letto da un’altra antenna', 'Read by another antenna', 'Von einer anderen Antenne gelesen', 'Lu par une autre antenne'],
            'industrialLaundry.rejection.out_of_range' => ['Segnale troppo debole (fuori portata)', 'Signal too weak (out of range)', 'Signal zu schwach (außer Reichweite)', 'Signal trop faible (hors de portée)'],
            'industrialLaundry.rejection.duplicate' => ['Lettura duplicata', 'Duplicate read', 'Doppelte Lesung', 'Lecture en double'],
            'industrialLaundry.rejection.unknown_tag' => ['Transponder non registrato', 'Unregistered transponder', 'Nicht erfasster Transponder', 'Transpondeur non enregistré'],
            'industrialLaundry.rejection.condemned' => ['Capo condannato a straccio', 'Garment condemned as a rag', 'Teil ausgesondert', 'Vêtement réformé'],
            'industrialLaundry.rejection.in_mending' => ['Capo in rammendo', 'Garment in mending', 'Teil in der Reparatur', 'Vêtement en réparation'],
            'industrialLaundry.rejection.customer_mismatch' => ['Il capo appartiene a un altro cliente', 'The garment belongs to another customer', 'Das Teil gehört einem anderen Kunden', 'Le vêtement appartient à un autre client'],
            'industrialLaundry.rejection.already_in_laundry' => ['Capo già in lavanderia', 'Garment already in the laundry', 'Teil bereits in der Wäscherei', 'Vêtement déjà à la blanchisserie'],
            'industrialLaundry.rejection.already_delivered' => ['Capo già consegnato', 'Garment already delivered', 'Teil bereits geliefert', 'Vêtement déjà livré'],
            'industrialLaundry.rejection.reissue_blocked' => ['Riconsegna bloccata', 'Re-issue blocked', 'Wiederausgabe gesperrt', 'Remise bloquée'],
            'industrialLaundry.rejection.rabc_not_certified' => ['Lotto di lavaggio non ancora certificato RABC', 'Wash batch not yet RABC certified', 'Waschcharge noch nicht RABC-zertifiziert', 'Lot de lavage pas encore certifié RABC'],
            'industrialLaundry.rejection.ppe_wash_limit_reached' => ['Limite di lavaggi DPI raggiunto: inviato in rammendo', 'PPE wash limit reached: sent to mending', 'PSA-Waschlimit erreicht: in die Reparatur geleitet', 'Limite de lavages EPI atteinte : envoyé en réparation'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'industrialLaundry.error.generic' => ['Operazione non riuscita.', 'The operation failed.', 'Der Vorgang ist fehlgeschlagen.', 'L’opération a échoué.'],
            'industrialLaundry.error.invalid_direction' => ['Il verso della lettura non è valido.', 'The direction of the scan is not valid.', 'Die Richtung des Scans ist ungültig.', 'Le sens du passage n’est pas valide.'],
            'industrialLaundry.error.weight_negative' => ['Un peso non può essere negativo.', 'A weight cannot be negative.', 'Ein Gewicht darf nicht negativ sein.', 'Un poids ne peut pas être négatif.'],
            'industrialLaundry.error.gross_below_tare' => ['Il peso lordo (:gross kg) è inferiore alla tara del roll container (:tare kg).', 'The gross weight (:gross kg) is lower than the tare of the roll cage (:tare kg).', 'Das Bruttogewicht (:gross kg) liegt unter der Tara des Rollwagens (:tare kg).', 'Le poids brut (:gross kg) est inférieur à la tare du chariot (:tare kg).'],
            'industrialLaundry.error.gross_above_limit' => ['Il peso lordo (:gross kg) supera il limite del roll container (:limit kg).', 'The gross weight (:gross kg) is above the limit of the roll cage (:limit kg).', 'Das Bruttogewicht (:gross kg) überschreitet das Limit des Rollwagens (:limit kg).', 'Le poids brut (:gross kg) dépasse la limite du chariot (:limit kg).'],
            'industrialLaundry.error.roll_cage_inactive' => ['Il roll container non è attivo.', 'The roll cage is not active.', 'Der Rollwagen ist nicht aktiv.', 'Le chariot n’est pas actif.'],
            'industrialLaundry.error.roll_cage_unknown' => ['Il roll container :barcode non è registrato: indica la sua tara o registralo prima.', 'The roll cage :barcode is not registered: enter its tare weight or register it first.', 'Der Rollwagen :barcode ist nicht erfasst: Tara angeben oder zuerst erfassen.', 'Le chariot :barcode n’est pas enregistré : indiquez sa tare ou enregistrez-le d’abord.'],
            'industrialLaundry.error.contact_not_found' => ['Il cliente non è stato trovato.', 'The customer was not found.', 'Der Kunde wurde nicht gefunden.', 'Le client est introuvable.'],
            'industrialLaundry.error.epc_invalid' => ['L’EPC non è un codice esadecimale valido.', 'The EPC is not a valid hexadecimal code.', 'Der EPC ist kein gültiger Hexadezimalcode.', 'L’EPC n’est pas un code hexadécimal valide.'],
            'industrialLaundry.error.epc_taken' => ['L’EPC :epc è già registrato.', 'The EPC :epc is already registered.', 'Der EPC :epc ist bereits erfasst.', 'L’EPC :epc est déjà enregistré.'],
            'industrialLaundry.error.garment_condemned' => ['Il capo è condannato e non può essere modificato.', 'The garment is condemned and cannot be changed.', 'Das Teil ist ausgesondert und kann nicht geändert werden.', 'Le vêtement est réformé et ne peut plus être modifié.'],
            'industrialLaundry.error.article_type_invalid' => ['Il tipo di articolo non è valido.', 'The article type is not valid.', 'Der Artikeltyp ist ungültig.', 'Le type d’article n’est pas valide.'],
            'industrialLaundry.error.invalid_lifecycle_transition' => ['Il capo non può passare da :from a :to.', 'The garment cannot move from :from to :to.', 'Das Teil kann nicht von :from zu :to wechseln.', 'Le vêtement ne peut pas passer de :from à :to.'],
            'industrialLaundry.error.garment_not_nominal' => ['Solo le divise nominali e i DPI si assegnano a un dipendente.', 'Only personal workwear and PPE can be assigned to an employee.', 'Nur persönliche Arbeitskleidung und PSA können einem Mitarbeitenden zugeordnet werden.', 'Seuls les vêtements de travail nominatifs et les EPI sont attribués à un employé.'],
            'industrialLaundry.error.assignment_incomplete' => ['Indica il dipendente e l’armadietto.', 'Enter the employee and the locker.', 'Mitarbeitende und Spind angeben.', 'Indiquez l’employé et le casier.'],
            'industrialLaundry.error.assignment_customer_required' => ['Il capo non ha un cliente: scegli il cliente del dipendente.', 'The garment has no customer: choose the customer of the employee.', 'Das Teil hat keinen Kunden: Kunde der Mitarbeitenden wählen.', 'Le vêtement n’a pas de client : choisissez le client de l’employé.'],
            'industrialLaundry.error.locker_taken' => ['L’armadietto :locker appartiene a un altro dipendente.', 'The locker :locker belongs to another employee.', 'Der Spind :locker gehört einem anderen Mitarbeitenden.', 'Le casier :locker appartient à un autre employé.'],
            'industrialLaundry.error.no_ppe_alert' => ['Il capo non è al limite di lavaggi certificato né bloccato.', 'The garment is neither at its certified wash limit nor blocked.', 'Das Teil hat weder sein zertifiziertes Waschlimit erreicht noch ist es gesperrt.', 'Le vêtement n’a pas atteint sa limite de lavages certifiée et n’est pas bloqué.'],
            'industrialLaundry.error.ppe_limit_still_reached' => ['Il capo ha ancora superato il limite di lavaggi certificato: sostituiscilo o condannalo.', 'The garment is still past its certified wash limit: replace or condemn it.', 'Das Teil liegt weiterhin über seinem zertifizierten Waschlimit: ersetzen oder aussondern.', 'Le vêtement dépasse toujours sa limite de lavages certifiée : remplacez-le ou réformez-le.'],
            'industrialLaundry.error.garment_not_in_mending' => ['Il capo non è in rammendo.', 'The garment is not in mending.', 'Das Teil ist nicht in der Reparatur.', 'Le vêtement n’est pas en réparation.'],
            'industrialLaundry.error.replacement_epc_required' => ['Indica l’EPC del capo sostitutivo.', 'Enter the EPC of the replacement garment.', 'EPC des Ersatzteils angeben.', 'Indiquez l’EPC du vêtement de remplacement.'],
            'industrialLaundry.error.invalid_action' => ['L’azione non è valida.', 'The action is not valid.', 'Die Aktion ist ungültig.', 'L’action n’est pas valide.'],
            'industrialLaundry.error.program_unknown' => ['Il programma di lavaggio non è stato trovato.', 'The wash program was not found.', 'Das Waschprogramm wurde nicht gefunden.', 'Le programme de lavage est introuvable.'],
            'industrialLaundry.error.risk_zone_invalid' => ['La zona di rischio non è valida.', 'The risk zone is not valid.', 'Die Risikozone ist ungültig.', 'La zone de risque n’est pas valide.'],
            'industrialLaundry.error.batch_sessions_required' => ['Seleziona almeno un roll container sporco.', 'Select at least one soiled roll cage.', 'Mindestens einen schmutzigen Rollwagen auswählen.', 'Sélectionnez au moins un chariot sale.'],
            'industrialLaundry.error.batch_sessions_invalid' => ['In un lotto possono entrare solo roll container sporchi ricevuti.', 'Only soiled roll cages that were received can go in a batch.', 'In eine Charge dürfen nur erhaltene schmutzige Rollwagen.', 'Seuls les chariots sales reçus peuvent entrer dans un lot.'],
            'industrialLaundry.error.session_already_batched' => ['Un roll container è già in un altro lotto.', 'A roll cage is already in another batch.', 'Ein Rollwagen ist bereits in einer anderen Charge.', 'Un chariot est déjà dans un autre lot.'],
            'industrialLaundry.error.batch_code_taken' => ['Il codice lotto :code è già usato.', 'The batch code :code is already used.', 'Der Chargencode :code wird bereits verwendet.', 'Le code de lot :code est déjà utilisé.'],
            'industrialLaundry.error.batch_already_certified' => ['Il lotto è già certificato.', 'The batch is already certified.', 'Die Charge ist bereits zertifiziert.', 'Le lot est déjà certifié.'],
            'industrialLaundry.error.batch_not_pending' => ['Il lotto ha già un esito: lava di nuovo la biancheria in un nuovo lotto.', 'The batch already has a verdict: wash the linen again in a new batch.', 'Die Charge hat bereits ein Ergebnis: die Wäsche in einer neuen Charge erneut waschen.', 'Le lot a déjà un résultat : relavez le linge dans un nouveau lot.'],
            'industrialLaundry.error.nothing_to_bill' => ['Non c’è nulla da fatturare in questo periodo.', 'There is nothing to bill in this period.', 'In diesem Zeitraum gibt es nichts abzurechnen.', 'Il n’y a rien à facturer sur cette période.'],
            'industrialLaundry.error.contract_inactive' => ['Il contratto non è attivo.', 'The contract is not active.', 'Der Vertrag ist nicht aktiv.', 'Le contrat n’est pas actif.'],
            'industrialLaundry.error.period_invalid' => ['Il periodo non è valido.', 'The period is not valid.', 'Der Zeitraum ist ungültig.', 'La période n’est pas valide.'],
            'industrialLaundry.error.period_outside_contract' => ['Il periodo è fuori dalla validità del contratto.', 'The period is outside the validity of the contract.', 'Der Zeitraum liegt außerhalb der Vertragslaufzeit.', 'La période est en dehors de la validité du contrat.'],
            'industrialLaundry.error.billing_period_already_billed' => ['Il periodo (o una parte) è già stato fatturato per questo contratto.', 'The period (or part of it) was already billed for this contract.', 'Der Zeitraum (oder ein Teil davon) wurde für diesen Vertrag bereits abgerechnet.', 'La période (ou une partie) a déjà été facturée pour ce contrat.'],
            'industrialLaundry.error.billing_run_already_invoiced' => ['La fattura di questa fatturazione è già stata predisposta.', 'The invoice of this billing was already drafted.', 'Die Rechnung dieser Abrechnung wurde bereits erstellt.', 'La facture de cette facturation a déjà été préparée.'],
            'industrialLaundry.error.billing_model_invalid' => ['Il modello di fatturazione non è valido.', 'The billing model is not valid.', 'Das Abrechnungsmodell ist ungültig.', 'Le modèle de facturation n’est pas valide.'],
            'industrialLaundry.error.duplicate_article_line' => ['Un tipo di articolo può comparire una sola volta in un contratto.', 'An article type can appear only once in a contract.', 'Ein Artikeltyp darf in einem Vertrag nur einmal vorkommen.', 'Un type d’article ne peut apparaître qu’une fois dans un contrat.'],
            'industrialLaundry.error.season_incomplete' => ['Una stagione richiede il primo mese, l’ultimo mese e la percentuale minima.', 'A season needs its first month, its last month and its minimum percentage.', 'Eine Saison braucht ersten Monat, letzten Monat und Mindestprozentsatz.', 'Une saison demande son premier mois, son dernier mois et son pourcentage minimum.'],
            'industrialLaundry.error.contract_overlap' => ['Il cliente ha già un contratto attivo in questo periodo.', 'The customer already has an active contract in this period.', 'Der Kunde hat in diesem Zeitraum bereits einen aktiven Vertrag.', 'Le client a déjà un contrat actif sur cette période.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'industrialLaundry.field.segment' => ['Segmento', 'Segment', 'Segment', 'Segment'],
            'industrialLaundry.field.allSegments' => ['Tutti i segmenti', 'All segments', 'Alle Segmente', 'Tous les segments'],
            'industrialLaundry.field.customer' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'industrialLaundry.field.allCustomers' => ['Tutti i clienti', 'All customers', 'Alle Kunden', 'Tous les clients'],
            'industrialLaundry.field.contract' => ['Contratto', 'Contract', 'Vertrag', 'Contrat'],
            'industrialLaundry.field.parVersusCirculating' => ['In circolazione / dotazione', 'Circulating / par level', 'Umlauf / Sollbestand', 'En circulation / dotation'],
            'industrialLaundry.field.piecesMonth' => ['Capi consegnati nel mese', 'Garments delivered this month', 'Gelieferte Teile im Monat', 'Vêtements livrés ce mois'],
            'industrialLaundry.field.netKgDirty' => ['Kg netti sporco', 'Net kg soiled', 'Netto-kg schmutzig', 'Kg nets sale'],
            'industrialLaundry.field.netKgClean' => ['Kg netti pulito', 'Net kg clean', 'Netto-kg sauber', 'Kg nets propre'],
            'industrialLaundry.field.billingPreview' => ['Anteprima fatturazione del mese', 'Billing preview of the month', 'Abrechnungsvorschau des Monats', 'Aperçu de facturation du mois'],
            'industrialLaundry.field.articleType' => ['Articolo', 'Article', 'Artikel', 'Article'],
            'industrialLaundry.field.parLevel' => ['Dotazione', 'Par level', 'Sollbestand', 'Dotation'],
            'industrialLaundry.field.circulating' => ['In circolazione', 'Circulating', 'Im Umlauf', 'En circulation'],
            'industrialLaundry.field.coverage' => ['Copertura', 'Coverage', 'Abdeckung', 'Couverture'],
            'industrialLaundry.field.atCustomer' => ['Dal cliente', 'At the customer', 'Beim Kunden', 'Chez le client'],
            'industrialLaundry.field.inLaundry' => ['In lavanderia', 'In the laundry', 'In der Wäscherei', 'À la blanchisserie'],
            'industrialLaundry.field.inMending' => ['In rammendo', 'In mending', 'In der Reparatur', 'En réparation'],
            'industrialLaundry.field.gap' => ['Scarto', 'Gap', 'Abweichung', 'Écart'],
            'industrialLaundry.field.washAmount' => ['Lavaggio', 'Wash', 'Wäsche', 'Lavage'],
            'industrialLaundry.field.topUpAmount' => ['Minimo garantito', 'Guaranteed minimum', 'Garantiertes Minimum', 'Minimum garanti'],
            'industrialLaundry.field.rentalAmount' => ['Noleggio', 'Rental', 'Miete', 'Location'],
            'industrialLaundry.field.unreturnedAmount' => ['Non restituiti', 'Unreturned', 'Nicht zurückgegeben', 'Non restitués'],
            'industrialLaundry.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'industrialLaundry.field.employee' => ['Dipendente', 'Employee', 'Mitarbeitende', 'Employé'],
            'industrialLaundry.field.locker' => ['Armadietto / casella', 'Locker / pigeonhole', 'Spind / Fach', 'Casier / case'],
            'industrialLaundry.field.garments' => ['Capi', 'Garments', 'Teile', 'Vêtements'],
            'industrialLaundry.field.garment' => ['Capo (EPC)', 'Garment (EPC)', 'Teil (EPC)', 'Vêtement (EPC)'],
            'industrialLaundry.field.limitReachedOnly' => ['Solo limite raggiunto', 'Limit reached only', 'Nur erreichtes Limit', 'Limite atteinte seulement'],
            'industrialLaundry.field.epc' => ['EPC', 'EPC', 'EPC', 'EPC'],
            'industrialLaundry.field.wearer' => ['Indossatore / armadietto', 'Wearer / locker', 'Träger / Spind', 'Porteur / casier'],
            'industrialLaundry.field.standard' => ['Norma', 'Standard', 'Norm', 'Norme'],
            'industrialLaundry.field.category' => ['Cat.', 'Cat.', 'Kat.', 'Cat.'],
            'industrialLaundry.field.washCycles' => ['Lavaggi', 'Washes', 'Wäschen', 'Lavages'],
            'industrialLaundry.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'industrialLaundry.field.state' => ['Fase', 'Stage', 'Phase', 'Phase'],
            'industrialLaundry.field.reissueBlocked' => ['Riconsegna bloccata', 'Re-issue blocked', 'Wiederausgabe gesperrt', 'Remise bloquée'],
            'industrialLaundry.field.replacementEpc' => ['EPC del capo sostitutivo', 'EPC of the replacement garment', 'EPC des Ersatzteils', 'EPC du vêtement de remplacement'],
            'industrialLaundry.field.thresholdDays' => ['Soglia in giorni', 'Threshold in days', 'Schwelle in Tagen', 'Seuil en jours'],
            'industrialLaundry.field.lastDelivered' => ['Ultima consegna', 'Last delivery', 'Letzte Lieferung', 'Dernière livraison'],
            'industrialLaundry.field.dormantDays' => ['Giorni dormienti', 'Dormant days', 'Ruhetage', 'Jours dormants'],
            'industrialLaundry.field.gateAntenna' => ['Antenna del varco', 'Gate antenna', 'Torantenne', 'Antenne du portique'],
            'industrialLaundry.field.rollCage' => ['Roll container', 'Roll cage', 'Rollwagen', 'Chariot'],
            'industrialLaundry.field.direction' => ['Verso', 'Direction', 'Richtung', 'Sens'],
            'industrialLaundry.field.grossWeight' => ['Peso lordo (kg)', 'Gross weight (kg)', 'Bruttogewicht (kg)', 'Poids brut (kg)'],
            'industrialLaundry.field.tareWeight' => ['Tara (kg)', 'Tare (kg)', 'Tara (kg)', 'Tare (kg)'],
            'industrialLaundry.field.netWeight' => ['Peso netto', 'Net weight', 'Nettogewicht', 'Poids net'],
            'industrialLaundry.field.reads' => ['Letture EPC (una per riga: EPC, RSSI, antenna)', 'EPC reads (one per line: EPC, RSSI, antenna)', 'EPC-Lesungen (eine pro Zeile: EPC, RSSI, Antenne)', 'Lectures EPC (une par ligne : EPC, RSSI, antenne)'],
            'industrialLaundry.field.generateDdt' => ['Genera il DDT', 'Generate the delivery note (DDT)', 'Lieferschein (DDT) erstellen', 'Générer le bon de livraison (DDT)'],
            'industrialLaundry.field.accepted' => ['Accettati', 'Accepted', 'Akzeptiert', 'Acceptés'],
            'industrialLaundry.field.rejected' => ['Scartati', 'Rejected', 'Abgelehnt', 'Rejetés'],
            'industrialLaundry.field.ddt' => ['DDT', 'DDT', 'DDT', 'DDT'],
            'industrialLaundry.field.reason' => ['Motivo', 'Reason', 'Grund', 'Motif'],
            'industrialLaundry.field.rssi' => ['RSSI (dBm)', 'RSSI (dBm)', 'RSSI (dBm)', 'RSSI (dBm)'],
            'industrialLaundry.field.scannedAt' => ['Passaggio', 'Passage', 'Durchfahrt', 'Passage'],
            'industrialLaundry.field.program' => ['Programma', 'Program', 'Programm', 'Programme'],
            'industrialLaundry.field.riskZone' => ['Zona di rischio', 'Risk zone', 'Risikozone', 'Zone de risque'],
            'industrialLaundry.field.operator' => ['Operatore', 'Operator', 'Bedienung', 'Opérateur'],
            'industrialLaundry.field.soiledCages' => ['Roll container sporchi ricevuti', 'Soiled roll cages received', 'Erhaltene schmutzige Rollwagen', 'Chariots sales reçus'],
            'industrialLaundry.field.batchCode' => ['Lotto', 'Batch', 'Charge', 'Lot'],
            'industrialLaundry.field.targetMeasured' => ['Obiettivo / misurato', 'Target / measured', 'Ziel / gemessen', 'Objectif / mesuré'],
            'industrialLaundry.field.violations' => ['Non conformità', 'Violations', 'Abweichungen', 'Non-conformités'],
            'industrialLaundry.field.temperature' => ['Temperatura misurata (°C)', 'Measured temperature (°C)', 'Gemessene Temperatur (°C)', 'Température mesurée (°C)'],
            'industrialLaundry.field.holdMinutes' => ['Tempo di mantenimento (min)', 'Hold time (min)', 'Haltezeit (min)', 'Temps de maintien (min)'],
            'industrialLaundry.field.chemicalDosing' => ['Dosaggio chimico (ml/kg)', 'Chemical dosing (ml/kg)', 'Chemiedosierung (ml/kg)', 'Dosage chimique (ml/kg)'],
            'industrialLaundry.field.cfu' => ['Carica microbica (UFC per 25 cm²)', 'Microbial count (CFU per 25 cm²)', 'Keimzahl (KBE pro 25 cm²)', 'Charge microbienne (UFC par 25 cm²)'],
            'industrialLaundry.field.verifier' => ['Verificatore', 'Verifier', 'Prüfende', 'Vérificateur'],
            'industrialLaundry.field.periodStart' => ['Dal', 'From', 'Von', 'Du'],
            'industrialLaundry.field.periodEnd' => ['Al', 'To', 'Bis', 'Au'],
            'industrialLaundry.field.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'industrialLaundry.field.createInvoice' => ['Predisponi la fattura in bozza', 'Draft the invoice', 'Rechnungsentwurf erstellen', 'Préparer la facture en brouillon'],
            'industrialLaundry.field.pieces' => ['Capi lavati', 'Garments washed', 'Gewaschene Teile', 'Vêtements lavés'],
            'industrialLaundry.field.subtotal' => ['Imponibile', 'Subtotal', 'Zwischensumme', 'Sous-total'],
            'industrialLaundry.field.tax' => ['Imposta', 'Tax', 'Steuer', 'Taxe'],
            'industrialLaundry.field.total' => ['Totale', 'Total', 'Gesamt', 'Total'],
            'industrialLaundry.field.line' => ['Voce', 'Line', 'Position', 'Ligne'],
            'industrialLaundry.field.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'industrialLaundry.field.unitPrice' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'industrialLaundry.field.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'industrialLaundry.field.documentType' => ['Documento', 'Document', 'Dokument', 'Document'],
            'industrialLaundry.field.returnableContainers' => ['Roll container a rendere (inviati / rientrati / dal cliente)', 'Returnable roll cages (sent / back / at the customer)', 'Mehrweg-Rollwagen (gesendet / zurück / beim Kunden)', 'Chariots consignés (envoyés / rentrés / chez le client)'],
            'industrialLaundry.field.waterDischarge' => ['Scarico idrico industriale', 'Industrial water discharge', 'Industrielle Abwassermenge', 'Rejet d’eau industriel'],
            'industrialLaundry.field.invoice' => ['Fattura', 'Invoice', 'Rechnung', 'Facture'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'industrialLaundry.action.refresh' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'industrialLaundry.action.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'industrialLaundry.action.assign' => ['Assegna a un dipendente', 'Assign to an employee', 'Einem Mitarbeitenden zuordnen', 'Attribuer à un employé'],
            'industrialLaundry.action.release' => ['Libera', 'Release', 'Freigeben', 'Libérer'],
            'industrialLaundry.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'industrialLaundry.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'industrialLaundry.action.replace' => ['Sostituisci', 'Replace', 'Ersetzen', 'Remplacer'],
            'industrialLaundry.action.mend' => ['Manda in rammendo', 'Send to mending', 'In die Reparatur', 'Envoyer en réparation'],
            'industrialLaundry.action.condemn' => ['Condanna a straccio', 'Condemn as a rag', 'Aussondern', 'Réformer en chiffon'],
            'industrialLaundry.action.completeMending' => ['Rammendo concluso', 'Mending done', 'Reparatur beendet', 'Réparation terminée'],
            'industrialLaundry.action.submitScan' => ['Invia la lettura', 'Submit the scan', 'Lesung senden', 'Envoyer la lecture'],
            'industrialLaundry.action.createBatch' => ['Apri il lotto di lavaggio', 'Open the wash batch', 'Waschcharge eröffnen', 'Ouvrir le lot de lavage'],
            'industrialLaundry.action.certify' => ['Certifica', 'Certify', 'Zertifizieren', 'Certifier'],
            'industrialLaundry.action.preview' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'industrialLaundry.action.calculate' => ['Consolida la fatturazione', 'Consolidate the billing', 'Abrechnung konsolidieren', 'Consolider la facturation'],
            'industrialLaundry.action.issueInvoice' => ['Predisponi la fattura', 'Draft the invoice', 'Rechnung erstellen', 'Préparer la facture'],
            'industrialLaundry.confirm.release' => ['Liberare il capo dal dipendente e dall’armadietto?', 'Release the garment from the employee and the locker?', 'Das Teil von Mitarbeitenden und Spind lösen?', 'Libérer le vêtement de l’employé et du casier ?'],
            'industrialLaundry.confirm.mend' => ['Mandare il capo in rammendo? Resta bloccato finché non rientra sotto il limite.', 'Send the garment to mending? It stays blocked until it is back under the limit.', 'Das Teil in die Reparatur geben? Es bleibt gesperrt, bis es wieder unter dem Limit liegt.', 'Envoyer le vêtement en réparation ? Il reste bloqué tant qu’il n’est pas repassé sous la limite.'],
            'industrialLaundry.confirm.condemn' => ['Condannare il capo a straccio? L’operazione non si annulla.', 'Condemn the garment as a rag? This cannot be undone.', 'Das Teil aussondern? Das lässt sich nicht rückgängig machen.', 'Réformer le vêtement en chiffon ? Cette opération est irréversible.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'industrialLaundry.section.assignLocker' => ['Assegna un capo a dipendente e armadietto', 'Assign a garment to an employee and a locker', 'Teil einem Mitarbeitenden und einem Spind zuordnen', 'Attribuer un vêtement à un employé et à un casier'],
            'industrialLaundry.section.replacePpe' => ['Sostituzione del capo DPI', 'Replace the PPE garment', 'PSA-Teil ersetzen', 'Remplacement du vêtement EPI'],
            'industrialLaundry.section.dormantByCustomer' => ['Capi dormienti per cliente', 'Dormant garments per customer', 'Ruhende Teile pro Kunde', 'Vêtements dormants par client'],
            'industrialLaundry.section.scanConsole' => ['Console varco RFID UHF (lettura reale o simulata)', 'RFID UHF gate console (live or simulated read)', 'RFID-UHF-Tor-Konsole (echte oder simulierte Lesung)', 'Console du portique RFID UHF (lecture réelle ou simulée)'],
            'industrialLaundry.section.scanResult' => ['Esito della lettura', 'Result of the scan', 'Ergebnis der Lesung', 'Résultat de la lecture'],
            'industrialLaundry.section.recentScans' => ['Ultimi passaggi ai varchi', 'Latest gate passages', 'Letzte Durchfahrten an den Toren', 'Derniers passages aux portiques'],
            'industrialLaundry.section.newBatch' => ['Nuovo lotto di lavaggio', 'New wash batch', 'Neue Waschcharge', 'Nouveau lot de lavage'],
            'industrialLaundry.section.batchLog' => ['Registro dei lotti e certificati RABC', 'Batch log and RABC certificates', 'Chargenregister und RABC-Zertifikate', 'Registre des lots et certificats RABC'],
            'industrialLaundry.section.certifyBatch' => ['Certificazione UNI EN 14065 (RABC) del lotto', 'UNI EN 14065 (RABC) certification of the batch', 'UNI EN 14065 (RABC) Zertifizierung der Charge', 'Certification UNI EN 14065 (RABC) du lot'],
            'industrialLaundry.section.billingPeriod' => ['Periodo da fatturare', 'Period to bill', 'Abzurechnender Zeitraum', 'Période à facturer'],
            'industrialLaundry.section.billingRuns' => ['Fatturazioni consolidate', 'Consolidated billings', 'Konsolidierte Abrechnungen', 'Facturations consolidées'],
            'industrialLaundry.empty.parStock' => ['Nessun contratto di dotazione attivo.', 'No active par stock contract.', 'Kein aktiver Sollbestandsvertrag.', 'Aucun contrat de dotation actif.'],
            'industrialLaundry.empty.lockers' => ['Nessun capo è assegnato a un dipendente.', 'No garment is assigned to an employee.', 'Kein Teil ist einem Mitarbeitenden zugeordnet.', 'Aucun vêtement n’est attribué à un employé.'],
            'industrialLaundry.empty.ppe' => ['Nessun capo DPI vicino al limite di lavaggi.', 'No PPE garment is near its wash limit.', 'Kein PSA-Teil liegt nahe am Waschlimit.', 'Aucun vêtement EPI n’est proche de sa limite de lavages.'],
            'industrialLaundry.empty.dormant' => ['Nessun capo dormiente.', 'No dormant garment.', 'Keine ruhenden Teile.', 'Aucun vêtement dormant.'],
            'industrialLaundry.empty.batches' => ['Nessun lotto di lavaggio registrato.', 'No wash batch recorded.', 'Keine Waschcharge erfasst.', 'Aucun lot de lavage enregistré.'],
            'industrialLaundry.empty.billingRuns' => ['Nessuna fatturazione consolidata.', 'No consolidated billing.', 'Keine konsolidierte Abrechnung.', 'Aucune facturation consolidée.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function hints(): array
    {
        return [
            'industrialLaundry.hint.previewPeriod' => ['Periodo dell’anteprima:', 'Preview period:', 'Zeitraum der Vorschau:', 'Période de l’aperçu :'],
            'industrialLaundry.hint.assignLocker' => ['Solo divise nominali e DPI. Un armadietto appartiene a un solo dipendente del cliente.', 'Only personal workwear and PPE. A locker belongs to one employee of the customer.', 'Nur persönliche Arbeitskleidung und PSA. Ein Spind gehört einem Mitarbeitenden des Kunden.', 'Uniquement vêtements nominatifs et EPI. Un casier appartient à un seul employé du client.'],
            'industrialLaundry.hint.replacePpe' => ['Il capo attuale viene condannato a straccio; il sostituto eredita cliente, dipendente, armadietto e limite certificato, con zero lavaggi.', 'The current garment is condemned as a rag; the replacement inherits the customer, employee, locker and certified limit, with zero washes.', 'Das aktuelle Teil wird ausgesondert; das Ersatzteil übernimmt Kunde, Mitarbeitende, Spind und zertifiziertes Limit mit null Wäschen.', 'Le vêtement actuel est réformé ; le remplaçant reprend le client, l’employé, le casier et la limite certifiée, avec zéro lavage.'],
            'industrialLaundry.hint.reads' => ['Letture da incollare dal lettore UHF. Duplicati, segnali deboli ed EPC non validi vengono scartati.', 'Reads pasted from the UHF reader. Duplicates, weak signals and invalid EPCs are discarded.', 'Vom UHF-Leser eingefügte Lesungen. Duplikate, schwache Signale und ungültige EPCs werden verworfen.', 'Lectures collées depuis le lecteur UHF. Les doublons, les signaux faibles et les EPC invalides sont écartés.'],
            'industrialLaundry.hint.programTargets' => ['Obiettivi del programma', 'Program targets', 'Programmziele', 'Objectifs du programme'],
            'industrialLaundry.hint.certify' => ['Il lotto è conforme solo se temperatura, tempo, dosaggio e carica microbica rispettano il programma e la UNI EN 14065; altrimenti torna a sporco e va rilavato.', 'The batch is compliant only if temperature, time, dosing and microbial count meet the program and UNI EN 14065; otherwise its garments go back to soiled and are washed again.', 'Die Charge ist nur konform, wenn Temperatur, Zeit, Dosierung und Keimzahl Programm und UNI EN 14065 erfüllen; sonst gehen die Teile zurück auf schmutzig und werden erneut gewaschen.', 'Le lot n’est conforme que si la température, le temps, le dosage et la charge microbienne respectent le programme et la UNI EN 14065 ; sinon ses vêtements repassent à sale et sont relavés.'],
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
