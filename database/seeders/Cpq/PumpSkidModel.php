<?php

declare(strict_types=1);

namespace Database\Seeders\Cpq;

/**
 * Demo model of the CPQ configurator (Mod 230): a modular industrial pump skid with AISI 304/316 or cast-iron wetted parts,
 * single-phase and three-phase supply, ATEX zones, the Swiss ESTI/SUVA safety kit, a dynamic super-BOM, routing and pricing.
 * Every text is [it, en, de, fr] and is served to the configurator by the API.
 */
final class PumpSkidModel
{
    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'code' => 'PUMP-SKID',
            'name' => CpqText::t('Gruppo pompa industriale modulare', 'Modular industrial pump skid', 'Modulare Industriepumpen-Einheit', 'Groupe de pompage industriel modulaire'),
            'description' => CpqText::t('Gruppo di pompaggio configurabile su skid con motore, quadro di comando e accessori di sicurezza.', 'Configurable skid-mounted pumping unit with motor, control panel and safety accessories.', 'Konfigurierbare Pumpeneinheit auf Grundrahmen mit Motor, Schaltschrank und Sicherheitszubehör.', 'Groupe de pompage configurable sur châssis avec moteur, armoire de commande et accessoires de sécurité.'),
            'currency' => 'EUR',
            'base_price' => 0,
            'base_cost' => 350,
            'base_lead_time_days' => 10,
            'target_margin_percent' => 32,
            'max_discount_percent' => 15,
            'part_number_prefix' => 'PSK',
            'steps' => [
                ['code' => 'application', 'title' => CpqText::t('Applicazione', 'Application', 'Anwendung', 'Application'), 'description' => CpqText::t('Paese di destinazione, fluido e zona ATEX.', 'Destination country, fluid and ATEX zone.', 'Zielland, Medium und ATEX-Zone.', 'Pays de destination, fluide et zone ATEX.')],
                ['code' => 'hydraulics', 'title' => CpqText::t('Idraulica e materiali', 'Hydraulics and materials', 'Hydraulik und Werkstoffe', 'Hydraulique et matériaux'), 'description' => CpqText::t('Portata, prevalenza e materiale delle parti a contatto.', 'Flow rate, head and material of the wetted parts.', 'Förderstrom, Förderhöhe und Werkstoff der medienberührten Teile.', 'Débit, hauteur manométrique et matériau des pièces en contact.')],
                ['code' => 'electrical', 'title' => CpqText::t('Parte elettrica', 'Electrical', 'Elektrik', 'Partie électrique'), 'description' => CpqText::t('Alimentazione, motore e quadro di comando.', 'Supply, motor and control panel.', 'Versorgung, Motor und Schaltschrank.', 'Alimentation, moteur et armoire de commande.')],
                ['code' => 'options', 'title' => CpqText::t('Accessori e collaudo', 'Accessories and testing', 'Zubehör und Prüfung', 'Accessoires et essais'), 'description' => CpqText::t('Kit di sicurezza, accessori, cavo, collaudo e garanzia.', 'Safety kit, accessories, cable, testing and warranty.', 'Sicherheitskit, Zubehör, Kabel, Prüfung und Garantie.', 'Kit de sécurité, accessoires, câble, essais et garantie.')],
            ],
            'visual_base' => [
                'shape' => 'body',
                'color' => '#bfc5cc',
                'label' => CpqText::t('Skid con pompa', 'Pump skid', 'Pumpen-Grundrahmen', 'Châssis de pompe'),
                'scale' => ['characteristic' => 'flow_rate', 'min_factor' => 0.8, 'max_factor' => 1.3],
            ],
            'proposal_texts' => [
                'validity_days' => 30,
                'title' => CpqText::t('Proposta tecnico-commerciale — gruppo pompa', 'Technical and commercial proposal — pump skid', 'Technisch-kommerzielles Angebot — Pumpeneinheit', 'Proposition technique et commerciale — groupe de pompage'),
                'intro' => CpqText::t('Di seguito la configurazione tecnica e le condizioni commerciali del gruppo di pompaggio richiesto.', 'Below the technical configuration and the commercial terms of the requested pumping unit.', 'Nachfolgend die technische Konfiguration und die kommerziellen Bedingungen der angefragten Pumpeneinheit.', 'Ci-dessous la configuration technique et les conditions commerciales du groupe de pompage demandé.'),
                'warranty' => CpqText::t('Garanzia contro i difetti di materiale e di fabbricazione per il periodo indicato nella configurazione, a decorrere dalla messa in servizio e comunque non oltre 6 mesi dalla consegna.', 'Warranty against defects in material and workmanship for the period stated in the configuration, from commissioning and in any case no later than 6 months after delivery.', 'Garantie auf Material- und Verarbeitungsfehler für den in der Konfiguration genannten Zeitraum ab Inbetriebnahme, spätestens 6 Monate nach Lieferung.', 'Garantie contre les défauts de matière et de fabrication pour la durée indiquée dans la configuration, à compter de la mise en service et au plus tard 6 mois après la livraison.'),
                'terms' => CpqText::t('Prezzi franco fabbrica (EXW), IVA esclusa. Pagamento 30% all’ordine, 60% alla spedizione, 10% al collaudo. Il prezzo è valido per il periodo di validità indicato.', 'Prices ex works (EXW), VAT excluded. Payment 30% with the order, 60% on shipment, 10% on acceptance. The price is valid for the stated validity period.', 'Preise ab Werk (EXW), exkl. MwSt. Zahlung 30 % bei Bestellung, 60 % bei Versand, 10 % nach Abnahme. Der Preis gilt für die angegebene Gültigkeitsdauer.', 'Prix départ usine (EXW), hors TVA. Paiement 30 % à la commande, 60 % à l’expédition, 10 % à la réception. Le prix est valable pendant la durée de validité indiquée.'),
                'delivery' => CpqText::t('Il termine di consegna decorre dalla conferma d’ordine e dal ricevimento dei dati tecnici completi.', 'The delivery time runs from order confirmation and receipt of the complete technical data.', 'Die Lieferzeit beginnt mit der Auftragsbestätigung und dem Eingang der vollständigen technischen Daten.', 'Le délai de livraison court à partir de la confirmation de commande et de la réception des données techniques complètes.'),
                'installation' => CpqText::t('Installazione su basamento piano e livellato; allacciamenti idraulici ed elettrici a cura del cliente secondo gli schemi forniti.', 'Installation on a flat, levelled base; hydraulic and electrical connections by the customer according to the supplied drawings.', 'Aufstellung auf ebenem, nivelliertem Fundament; hydraulische und elektrische Anschlüsse durch den Kunden gemäß den gelieferten Plänen.', 'Installation sur un socle plan et nivelé ; raccordements hydrauliques et électriques à la charge du client selon les schémas fournis.'),
                'dimensional_notes' => [
                    CpqText::t('Ingombro indicativo del basamento: da 1,6 × 0,8 m a 2,4 × 1,0 m in funzione della portata.', 'Indicative skid footprint: from 1.6 × 0.8 m to 2.4 × 1.0 m depending on the flow rate.', 'Richtwert Grundrahmen: 1,6 × 0,8 m bis 2,4 × 1,0 m je nach Förderstrom.', 'Encombrement indicatif du châssis : de 1,6 × 0,8 m à 2,4 × 1,0 m selon le débit.'),
                    CpqText::t('Spazio di servizio minimo di 0,8 m su tutti i lati per manutenzione.', 'Minimum service clearance of 0.8 m on all sides for maintenance.', 'Mindestabstand für Wartung von 0,8 m an allen Seiten.', 'Espace de service minimal de 0,8 m sur tous les côtés pour la maintenance.'),
                ],
            ],
            'characteristics' => self::characteristics(),
            'rules' => self::rules(),
            'bom' => self::bom(),
            'routing' => self::routing(),
            'pricing' => self::pricing(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function characteristics(): array
    {
        $t = CpqText::t(...);

        return [
            ['code' => 'destination_country', 'step_code' => 'application', 'type' => 'list', 'label' => $t('Paese di destinazione', 'Destination country', 'Zielland', 'Pays de destination'), 'help' => $t('Determina le dotazioni di sicurezza obbligatorie e le certificazioni.', 'Determines the mandatory safety equipment and the certifications.', 'Bestimmt die obligatorische Sicherheitsausstattung und die Zertifizierungen.', 'Détermine les équipements de sécurité obligatoires et les certifications.'), 'part_number' => ['position' => 8], 'sort_order' => 10, 'options' => [
                ['code' => 'IT', 'label' => $t('Italia', 'Italy', 'Italien', 'Italie'), 'part_number_code' => 'IT', 'is_default' => true],
                ['code' => 'CH', 'label' => $t('Svizzera', 'Switzerland', 'Schweiz', 'Suisse'), 'part_number_code' => 'CH'],
                ['code' => 'DE', 'label' => $t('Germania', 'Germany', 'Deutschland', 'Allemagne'), 'part_number_code' => 'DE'],
                ['code' => 'FR', 'label' => $t('Francia', 'France', 'Frankreich', 'France'), 'part_number_code' => 'FR'],
            ]],
            ['code' => 'fluid', 'step_code' => 'application', 'type' => 'list', 'label' => $t('Fluido pompato', 'Pumped fluid', 'Fördermedium', 'Fluide pompé'), 'help' => $t('I fluidi chimici aggressivi richiedono acciaio inox AISI 316.', 'Aggressive chemical fluids require AISI 316 stainless steel.', 'Aggressive chemische Medien erfordern Edelstahl AISI 316.', 'Les fluides chimiques agressifs exigent de l’acier inoxydable AISI 316.'), 'sort_order' => 20, 'options' => [
                ['code' => 'WATER', 'label' => $t('Acqua', 'Water', 'Wasser', 'Eau'), 'is_default' => true],
                ['code' => 'OIL', 'label' => $t('Olio', 'Oil', 'Öl', 'Huile')],
                ['code' => 'CHEM', 'label' => $t('Fluido chimico aggressivo', 'Aggressive chemical fluid', 'Aggressives chemisches Medium', 'Fluide chimique agressif')],
            ]],
            ['code' => 'atex_zone', 'step_code' => 'application', 'type' => 'list', 'label' => $t('Zona ATEX', 'ATEX zone', 'ATEX-Zone', 'Zone ATEX'), 'help' => $t('Zona con atmosfera potenzialmente esplosiva dell’installazione.', 'Potentially explosive atmosphere zone of the installation.', 'Zone mit explosionsfähiger Atmosphäre am Aufstellort.', 'Zone à atmosphère explosive de l’installation.'), 'part_number' => ['position' => 3], 'sort_order' => 30, 'options' => [
                ['code' => 'NONE', 'label' => $t('Nessuna (area sicura)', 'None (safe area)', 'Keine (sicherer Bereich)', 'Aucune (zone sûre)'), 'is_default' => true, 'part_number_code' => ''],
                ['code' => 'ZONE_2', 'label' => $t('Zona 2', 'Zone 2', 'Zone 2', 'Zone 2'), 'part_number_code' => 'X2', 'visual' => ['layer' => 'certification', 'shape' => 'badge', 'color' => '#fa8c16', 'z' => 50]],
                ['code' => 'ZONE_1', 'label' => $t('Zona 1', 'Zone 1', 'Zone 1', 'Zone 1'), 'part_number_code' => 'X1', 'visual' => ['layer' => 'certification', 'shape' => 'badge', 'color' => '#f5222d', 'z' => 50]],
            ]],
            ['code' => 'flow_rate', 'step_code' => 'hydraulics', 'type' => 'range', 'label' => $t('Portata', 'Flow rate', 'Förderstrom', 'Débit'), 'help' => $t('Portata nominale al punto di lavoro.', 'Nominal flow rate at the duty point.', 'Nennförderstrom im Betriebspunkt.', 'Débit nominal au point de fonctionnement.'), 'unit' => 'm³/h', 'range_min' => 5, 'range_max' => 120, 'range_step' => 5, 'default_value' => '40', 'part_number' => ['position' => 5, 'prefix' => 'Q'], 'sort_order' => 40],
            ['code' => 'head', 'step_code' => 'hydraulics', 'type' => 'range', 'label' => $t('Prevalenza', 'Head', 'Förderhöhe', 'Hauteur manométrique'), 'help' => $t('Prevalenza totale richiesta.', 'Total head required.', 'Erforderliche Gesamtförderhöhe.', 'Hauteur manométrique totale requise.'), 'unit' => 'm', 'range_min' => 10, 'range_max' => 80, 'range_step' => 5, 'default_value' => '30', 'part_number' => ['position' => 6, 'prefix' => 'H'], 'sort_order' => 50],
            ['code' => 'material', 'step_code' => 'hydraulics', 'type' => 'list', 'label' => $t('Materiale parti bagnate', 'Wetted parts material', 'Werkstoff medienberührter Teile', 'Matériau des pièces en contact'), 'help' => $t('AISI 316 per ambienti chimici e marini; AISI 304 per uso alimentare e generale.', 'AISI 316 for chemical and marine environments; AISI 304 for food and general use.', 'AISI 316 für chemische und maritime Umgebung; AISI 304 für Lebensmittel und allgemeinen Einsatz.', 'AISI 316 pour les milieux chimiques et marins ; AISI 304 pour l’alimentaire et l’usage général.'), 'part_number' => ['position' => 1], 'sort_order' => 60, 'options' => [
                ['code' => 'AISI_304', 'label' => $t('Acciaio inox AISI 304', 'AISI 304 stainless steel', 'Edelstahl AISI 304', 'Acier inoxydable AISI 304'), 'part_number_code' => 'S4', 'is_default' => true, 'properties' => ['density' => 7.9], 'visual' => ['layer' => 'material', 'shape' => 'body_finish', 'color' => '#c9d1d9', 'z' => 10]],
                ['code' => 'AISI_316', 'label' => $t('Acciaio inox AISI 316', 'AISI 316 stainless steel', 'Edelstahl AISI 316', 'Acier inoxydable AISI 316'), 'part_number_code' => 'S6', 'properties' => ['density' => 8.0], 'visual' => ['layer' => 'material', 'shape' => 'body_finish', 'color' => '#9fb3c8', 'z' => 10]],
                ['code' => 'CAST_IRON', 'label' => $t('Ghisa', 'Cast iron', 'Gusseisen', 'Fonte'), 'part_number_code' => 'CI', 'properties' => ['density' => 7.2], 'visual' => ['layer' => 'material', 'shape' => 'body_finish', 'color' => '#6b7280', 'z' => 10]],
            ]],
            ['code' => 'voltage', 'step_code' => 'electrical', 'type' => 'list', 'label' => $t('Alimentazione', 'Supply', 'Versorgung', 'Alimentation'), 'help' => $t('Tensione e fasi della rete disponibile.', 'Voltage and phases of the available mains.', 'Spannung und Phasen des verfügbaren Netzes.', 'Tension et phases du réseau disponible.'), 'part_number' => ['position' => 2], 'sort_order' => 70, 'options' => [
                ['code' => 'V230_1P', 'label' => $t('230 V monofase', '230 V single-phase', '230 V einphasig', '230 V monophasé'), 'part_number_code' => '230'],
                ['code' => 'V400_3P', 'label' => $t('400 V trifase', '400 V three-phase', '400 V dreiphasig', '400 V triphasé'), 'part_number_code' => '400', 'is_default' => true],
                ['code' => 'V690_3P', 'label' => $t('690 V trifase', '690 V three-phase', '690 V dreiphasig', '690 V triphasé'), 'part_number_code' => '690'],
            ]],
            ['code' => 'motor_type', 'step_code' => 'electrical', 'type' => 'list', 'label' => $t('Esecuzione del motore', 'Motor design', 'Motorausführung', 'Exécution du moteur'), 'help' => $t('Il motore antideflagrante è incluso automaticamente nelle zone ATEX.', 'The explosion-proof motor is included automatically in ATEX zones.', 'Der explosionsgeschützte Motor wird in ATEX-Zonen automatisch eingeschlossen.', 'Le moteur antidéflagrant est inclus automatiquement dans les zones ATEX.'), 'sort_order' => 80, 'options' => [
                ['code' => 'STANDARD_IE3', 'label' => $t('Standard IE3', 'Standard IE3', 'Standard IE3', 'Standard IE3'), 'is_default' => true, 'visual' => ['layer' => 'motor', 'shape' => 'motor', 'color' => '#1d4ed8', 'z' => 20]],
                ['code' => 'ATEX_EXD', 'label' => $t('Antideflagrante ATEX Ex d', 'ATEX Ex d explosion-proof', 'ATEX Ex d explosionsgeschützt', 'Antidéflagrant ATEX Ex d'), 'visual' => ['layer' => 'motor', 'shape' => 'motor', 'color' => '#d4380d', 'z' => 20]],
            ]],
            ['code' => 'control_panel', 'step_code' => 'electrical', 'type' => 'list', 'label' => $t('Quadro di comando', 'Control panel', 'Schaltschrank', 'Armoire de commande'), 'part_number' => ['position' => 4], 'sort_order' => 90, 'options' => [
                ['code' => 'BASIC', 'label' => $t('Avviamento diretto', 'Direct-on-line starter', 'Direktstarter', 'Démarreur direct'), 'part_number_code' => 'B', 'is_default' => true, 'visual' => ['layer' => 'electrical', 'shape' => 'panel', 'color' => '#52c41a', 'z' => 30]],
                ['code' => 'VFD', 'label' => $t('Inverter (VFD)', 'Variable frequency drive (VFD)', 'Frequenzumrichter (VFD)', 'Variateur de fréquence (VFD)'), 'part_number_code' => 'V', 'visual' => ['layer' => 'electrical', 'shape' => 'panel', 'color' => '#13c2c2', 'z' => 30]],
                ['code' => 'PLC', 'label' => $t('PLC con telecontrollo', 'PLC with remote control', 'SPS mit Fernüberwachung', 'API avec télécontrôle'), 'part_number_code' => 'P', 'visual' => ['layer' => 'electrical', 'shape' => 'panel', 'color' => '#722ed1', 'z' => 30]],
            ]],
            ['code' => 'safety_kit', 'step_code' => 'options', 'type' => 'boolean', 'label' => $t('Kit di sicurezza SUVA/ESTI', 'SUVA/ESTI safety kit', 'SUVA/ESTI-Sicherheitskit', 'Kit de sécurité SUVA/ESTI'), 'help' => $t('Protezioni, arresto di emergenza e marcatura conformi a SUVA ed ESTI. Obbligatorio per la Svizzera.', 'Guards, emergency stop and marking compliant with SUVA and ESTI. Mandatory for Switzerland.', 'Schutzeinrichtungen, Not-Halt und Kennzeichnung nach SUVA und ESTI. Für die Schweiz obligatorisch.', 'Protections, arrêt d’urgence et marquage conformes à SUVA et ESTI. Obligatoire pour la Suisse.'), 'is_required' => false, 'default_value' => 'false', 'part_number' => ['position' => 7, 'true_code' => 'SK'], 'visual' => ['layer' => 'safety', 'shape' => 'kit', 'color' => '#fadb14', 'z' => 40], 'sort_order' => 100],
            ['code' => 'flush_kit', 'step_code' => 'options', 'type' => 'boolean', 'label' => $t('Kit di lavaggio tenuta', 'Seal flush kit', 'Dichtungsspülung', 'Kit de rinçage de garniture'), 'help' => $t('Disponibile solo per fluidi chimici aggressivi.', 'Available only for aggressive chemical fluids.', 'Nur für aggressive chemische Medien verfügbar.', 'Disponible uniquement pour les fluides chimiques agressifs.'), 'is_required' => false, 'default_value' => 'false', 'visual' => ['layer' => 'accessory', 'shape' => 'accessory', 'color' => '#2f54eb', 'z' => 60, 'slot' => 1], 'sort_order' => 110],
            ['code' => 'cable_length_m', 'step_code' => 'options', 'type' => 'range', 'label' => $t('Lunghezza cavo di collegamento', 'Connection cable length', 'Länge des Anschlusskabels', 'Longueur du câble de raccordement'), 'unit' => 'm', 'range_min' => 5, 'range_max' => 100, 'range_step' => 5, 'default_value' => '10', 'sort_order' => 120],
            ['code' => 'test_level', 'step_code' => 'options', 'type' => 'list', 'label' => $t('Collaudo', 'Factory test', 'Werksprüfung', 'Essai en usine'), 'sort_order' => 130, 'options' => [
                ['code' => 'STANDARD', 'label' => $t('Collaudo standard', 'Standard test', 'Standardprüfung', 'Essai standard'), 'is_default' => true, 'properties' => ['hours' => 2]],
                ['code' => 'WITNESS', 'label' => $t('Collaudo alla presenza del cliente', 'Witnessed test', 'Abnahmeprüfung mit Kunde', 'Essai en présence du client'), 'properties' => ['hours' => 8]],
            ]],
            ['code' => 'warranty_years', 'step_code' => 'options', 'type' => 'range', 'label' => $t('Garanzia', 'Warranty', 'Garantie', 'Garantie'), 'unit' => 'y', 'range_min' => 1, 'range_max' => 5, 'range_step' => 1, 'default_value' => '2', 'sort_order' => 140],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rules(): array
    {
        $t = CpqText::t(...);

        return [
            ['code' => 'motor-sizing', 'rule_type' => 'decision_table', 'priority' => 10, 'params' => ['require_match' => false, 'rows' => [
                ['when' => 'flow_rate * head <= 1000', 'outputs' => ['motor_kw' => 4]],
                ['when' => 'flow_rate * head <= 2500', 'outputs' => ['motor_kw' => 7.5]],
                ['when' => 'flow_rate * head <= 4500', 'outputs' => ['motor_kw' => 15]],
                ['when' => 'true', 'outputs' => ['motor_kw' => 30]],
            ]], 'message' => $t('Motore dimensionato in base a portata e prevalenza.', 'Motor sized from flow rate and head.', 'Motor nach Förderstrom und Förderhöhe dimensioniert.', 'Moteur dimensionné selon le débit et la hauteur.')],
            ['code' => 'ch-safety-kit', 'rule_type' => 'force_value', 'priority' => 20, 'condition_expression' => "destination_country == 'CH'", 'target_characteristic' => 'safety_kit', 'forced_value' => true, 'message' => $t('Per la destinazione Svizzera il kit di sicurezza SUVA/ESTI è obbligatorio e viene incluso automaticamente.', 'For destination Switzerland the SUVA/ESTI safety kit is mandatory and is included automatically.', 'Für das Zielland Schweiz ist das SUVA/ESTI-Sicherheitskit obligatorisch und wird automatisch eingeschlossen.', 'Pour la destination Suisse, le kit de sécurité SUVA/ESTI est obligatoire et inclus automatiquement.')],
            ['code' => 'atex-motor', 'rule_type' => 'force_value', 'priority' => 20, 'condition_expression' => "atex_zone in ['ZONE_1', 'ZONE_2']", 'target_characteristic' => 'motor_type', 'forced_value' => 'ATEX_EXD', 'message' => $t('In zona ATEX {atex_zone} è obbligatorio il motore antideflagrante Ex d: incluso automaticamente.', 'In ATEX {atex_zone} the Ex d explosion-proof motor is mandatory: included automatically.', 'In ATEX {atex_zone} ist der Ex-d-Motor obligatorisch: automatisch eingeschlossen.', 'En zone ATEX {atex_zone}, le moteur antidéflagrant Ex d est obligatoire : inclus automatiquement.')],
            ['code' => 'chem-material', 'rule_type' => 'exclude_options', 'priority' => 30, 'condition_expression' => "fluid == 'CHEM'", 'target_characteristic' => 'material', 'target_options' => ['AISI_304', 'CAST_IRON'], 'message' => $t('Il fluido chimico aggressivo non è compatibile con AISI 304 e ghisa: scegliere AISI 316.', 'The aggressive chemical fluid is not compatible with AISI 304 and cast iron: choose AISI 316.', 'Das aggressive chemische Medium ist mit AISI 304 und Gusseisen nicht verträglich: AISI 316 wählen.', 'Le fluide chimique agressif n’est pas compatible avec l’AISI 304 et la fonte : choisir l’AISI 316.')],
            ['code' => 'atex-cast-iron', 'rule_type' => 'exclude_options', 'priority' => 30, 'condition_expression' => "atex_zone == 'ZONE_1'", 'target_characteristic' => 'material', 'target_options' => ['CAST_IRON'], 'message' => $t('In zona ATEX 1 la ghisa non è ammessa per le parti bagnate: usare acciaio inox.', 'In ATEX zone 1 cast iron is not allowed for wetted parts: use stainless steel.', 'In ATEX-Zone 1 ist Gusseisen für medienberührte Teile nicht zulässig: Edelstahl verwenden.', 'En zone ATEX 1, la fonte n’est pas admise pour les pièces en contact : utiliser de l’acier inoxydable.')],
            ['code' => 'single-phase-power', 'rule_type' => 'exclude_options', 'priority' => 30, 'condition_expression' => 'motor_kw > 2.2', 'target_characteristic' => 'voltage', 'target_options' => ['V230_1P'], 'message' => $t('Il motore calcolato ({motor_kw} kW) supera i 2,2 kW: l’alimentazione monofase 230 V non è disponibile, scegliere trifase.', 'The calculated motor ({motor_kw} kW) exceeds 2.2 kW: single-phase 230 V supply is not available, choose three-phase.', 'Der berechnete Motor ({motor_kw} kW) überschreitet 2,2 kW: einphasige 230-V-Versorgung nicht verfügbar, dreiphasig wählen.', 'Le moteur calculé ({motor_kw} kW) dépasse 2,2 kW : l’alimentation monophasée 230 V n’est pas disponible, choisir le triphasé.')],
            ['code' => 'high-voltage-vfd', 'rule_type' => 'exclude_options', 'priority' => 30, 'condition_expression' => "voltage == 'V690_3P'", 'target_characteristic' => 'control_panel', 'target_options' => ['BASIC'], 'message' => $t('Con alimentazione 690 V è richiesto un quadro con inverter o PLC.', 'With 690 V supply a panel with VFD or PLC is required.', 'Bei 690-V-Versorgung ist ein Schaltschrank mit Umrichter oder SPS erforderlich.', 'Avec une alimentation 690 V, une armoire avec variateur ou API est requise.')],
            ['code' => 'single-phase-flow', 'rule_type' => 'limit_range', 'priority' => 40, 'condition_expression' => "voltage == 'V230_1P'", 'target_characteristic' => 'flow_rate', 'params' => ['max' => 60], 'message' => $t('Con alimentazione monofase la portata massima è 60 m³/h.', 'With single-phase supply the maximum flow rate is 60 m³/h.', 'Bei einphasiger Versorgung beträgt der maximale Förderstrom 60 m³/h.', 'Avec une alimentation monophasée, le débit maximal est de 60 m³/h.')],
            ['code' => 'flush-only-chem', 'rule_type' => 'hide', 'priority' => 50, 'condition_expression' => "fluid != 'CHEM'", 'target_characteristic' => 'flush_kit', 'message' => $t('Il kit di lavaggio è disponibile solo con fluidi chimici.', 'The flush kit is only available with chemical fluids.', 'Die Spülung ist nur bei chemischen Medien verfügbar.', 'Le kit de rinçage n’est disponible qu’avec des fluides chimiques.')],
            ['code' => 'vfd-atex-warning', 'rule_type' => 'validate', 'priority' => 60, 'condition_expression' => "atex_zone != 'NONE' and control_panel != 'BASIC'", 'severity' => 'warning', 'message' => $t('Il quadro di comando deve essere installato in area sicura, fuori dalla zona ATEX.', 'The control panel must be installed in a safe area, outside the ATEX zone.', 'Der Schaltschrank muss in einem sicheren Bereich außerhalb der ATEX-Zone installiert werden.', 'L’armoire de commande doit être installée en zone sûre, hors de la zone ATEX.')],
            ['code' => 'witness-test-large', 'rule_type' => 'validate', 'priority' => 60, 'condition_expression' => "test_level == 'WITNESS' and motor_kw >= 30", 'severity' => 'info', 'message' => $t('Il collaudo alla presenza del cliente per motori da 30 kW richiede una prenotazione del banco prova.', 'A witnessed test for 30 kW motors requires booking the test bench.', 'Eine Abnahmeprüfung bei 30-kW-Motoren erfordert die Reservierung des Prüfstands.', 'Un essai en présence du client pour des moteurs de 30 kW exige la réservation du banc d’essai.')],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function bom(): array
    {
        $t = CpqText::t(...);

        return [
            ['code' => 'frame', 'item_code' => 'SKID-FRAME', 'item_name' => $t('Basamento skid', 'Skid frame', 'Grundrahmen', 'Châssis skid'), 'quantity_formula' => '1', 'unit_cost_formula' => '180 + flow_rate * 3.2', 'lead_time_days' => 7, 'sort_order' => 10],
            ['code' => 'body-304', 'item_code' => 'PUMP-BODY-304', 'item_name' => $t('Corpo pompa AISI 304', 'Pump body AISI 304', 'Pumpengehäuse AISI 304', 'Corps de pompe AISI 304'), 'condition_expression' => "material == 'AISI_304'", 'quantity_formula' => '1', 'unit_cost_formula' => '1250 + flow_rate * 8', 'lead_time_days' => 15, 'sort_order' => 20],
            ['code' => 'body-316', 'item_code' => 'PUMP-BODY-316', 'item_name' => $t('Corpo pompa AISI 316', 'Pump body AISI 316', 'Pumpengehäuse AISI 316', 'Corps de pompe AISI 316'), 'condition_expression' => "material == 'AISI_316'", 'quantity_formula' => '1', 'unit_cost_formula' => '1850 + flow_rate * 11', 'lead_time_days' => 20, 'sort_order' => 21],
            ['code' => 'body-iron', 'item_code' => 'PUMP-BODY-CI', 'item_name' => $t('Corpo pompa in ghisa', 'Cast-iron pump body', 'Pumpengehäuse Gusseisen', 'Corps de pompe en fonte'), 'condition_expression' => "material == 'CAST_IRON'", 'quantity_formula' => '1', 'unit_cost_formula' => '620 + flow_rate * 4.5', 'lead_time_days' => 10, 'sort_order' => 22],
            ['code' => 'motor-std', 'item_code' => 'MOTOR-IE3', 'item_name' => $t('Motore elettrico IE3', 'IE3 electric motor', 'Elektromotor IE3', 'Moteur électrique IE3'), 'condition_expression' => "motor_type == 'STANDARD_IE3'", 'quantity_formula' => '1', 'unit_cost_formula' => 'motor_kw * 95', 'lead_time_days' => 12, 'sort_order' => 30],
            ['code' => 'motor-atex', 'item_code' => 'MOTOR-EXD', 'item_name' => $t('Motore antideflagrante Ex d', 'Ex d explosion-proof motor', 'Ex-d-Motor explosionsgeschützt', 'Moteur antidéflagrant Ex d'), 'condition_expression' => "motor_type == 'ATEX_EXD'", 'quantity_formula' => '1', 'unit_cost_formula' => 'motor_kw * 210', 'lead_time_days' => 28, 'auto_include_message' => $t('Motore Ex d incluso per la zona ATEX scelta.', 'Ex d motor included for the selected ATEX zone.', 'Ex-d-Motor für die gewählte ATEX-Zone eingeschlossen.', 'Moteur Ex d inclus pour la zone ATEX choisie.'), 'sort_order' => 31],
            ['code' => 'panel-basic', 'item_code' => 'PANEL-DOL', 'item_name' => $t('Quadro avviamento diretto', 'Direct-on-line panel', 'Direktstarter-Schaltschrank', 'Armoire démarreur direct'), 'condition_expression' => "control_panel == 'BASIC'", 'quantity_formula' => '1', 'unit_cost' => 650, 'lead_time_days' => 5, 'sort_order' => 40],
            ['code' => 'panel-vfd', 'item_code' => 'PANEL-VFD', 'item_name' => $t('Quadro con inverter', 'VFD panel', 'Schaltschrank mit Umrichter', 'Armoire avec variateur'), 'condition_expression' => "control_panel == 'VFD'", 'quantity_formula' => '1', 'unit_cost_formula' => '900 + motor_kw * 60', 'lead_time_days' => 14, 'sort_order' => 41],
            ['code' => 'panel-plc', 'item_code' => 'PANEL-PLC', 'item_name' => $t('Quadro PLC con telecontrollo', 'PLC panel with remote control', 'SPS-Schaltschrank mit Fernüberwachung', 'Armoire API avec télécontrôle'), 'condition_expression' => "control_panel == 'PLC'", 'quantity_formula' => '1', 'unit_cost_formula' => '2400 + motor_kw * 60', 'lead_time_days' => 21, 'sort_order' => 42],
            ['code' => 'cable', 'item_code' => 'CABLE-4G', 'item_name' => $t('Cavo di potenza 4G', '4G power cable', 'Leistungskabel 4G', 'Câble de puissance 4G'), 'uom' => 'M', 'quantity_formula' => 'cable_length_m * 1.05', 'unit_cost_formula' => '4.2 + motor_kw * 0.35', 'lead_time_days' => 3, 'sort_order' => 50],
            ['code' => 'oil', 'item_code' => 'OIL-HYD', 'item_name' => $t('Olio idraulico di primo riempimento', 'First-fill hydraulic oil', 'Hydrauliköl Erstbefüllung', 'Huile hydraulique de première charge'), 'uom' => 'L', 'quantity_formula' => 'round(motor_kw * 0.8, 1)', 'unit_cost' => 7.5, 'lead_time_days' => 2, 'sort_order' => 60],
            ['code' => 'safety-kit', 'item_code' => 'KIT-SAFETY', 'item_name' => $t('Kit di sicurezza (protezioni e arresto di emergenza)', 'Safety kit (guards and emergency stop)', 'Sicherheitskit (Schutz und Not-Halt)', 'Kit de sécurité (protections et arrêt d’urgence)'), 'condition_expression' => 'safety_kit', 'quantity_formula' => '1', 'unit_cost' => 420, 'lead_time_days' => 6, 'auto_include_message' => $t('Kit di sicurezza richiesto dalla configurazione.', 'Safety kit required by the configuration.', 'Sicherheitskit durch die Konfiguration erforderlich.', 'Kit de sécurité requis par la configuration.'), 'sort_order' => 70],
            ['code' => 'esti-label', 'item_code' => 'LABEL-ESTI', 'item_name' => $t('Targhetta e dichiarazione ESTI/SUVA', 'ESTI/SUVA plate and declaration', 'ESTI/SUVA-Schild und Erklärung', 'Plaque et déclaration ESTI/SUVA'), 'condition_expression' => "destination_country == 'CH'", 'quantity_formula' => '1', 'unit_cost' => 85, 'lead_time_days' => 4, 'auto_include_message' => $t('Documentazione svizzera inclusa per la destinazione CH.', 'Swiss documentation included for destination CH.', 'Schweizer Dokumentation für Zielland CH eingeschlossen.', 'Documentation suisse incluse pour la destination CH.'), 'sort_order' => 71],
            ['code' => 'flush-kit', 'item_code' => 'KIT-FLUSH', 'item_name' => $t('Kit di lavaggio tenuta meccanica', 'Mechanical seal flush kit', 'Gleitringdichtungs-Spülkit', 'Kit de rinçage de garniture mécanique'), 'condition_expression' => 'flush_kit', 'quantity_formula' => '1', 'unit_cost' => 310, 'lead_time_days' => 8, 'sort_order' => 80],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function routing(): array
    {
        $t = CpqText::t(...);

        return [
            ['code' => 'assembly', 'operation_name' => $t('Assemblaggio meccanico', 'Mechanical assembly', 'Mechanische Montage', 'Assemblage mécanique'), 'work_center' => 'WC-ASM', 'setup_hours_formula' => '0.5', 'run_hours_formula' => '4 + motor_kw * 0.15', 'hourly_rate' => 55, 'sort_order' => 10],
            ['code' => 'welding', 'operation_name' => $t('Saldatura inox', 'Stainless welding', 'Edelstahlschweißen', 'Soudage inox'), 'work_center' => 'WC-WLD', 'condition_expression' => "material in ['AISI_304', 'AISI_316']", 'setup_hours_formula' => '1', 'run_hours_formula' => '3 + flow_rate * 0.02', 'hourly_rate' => 62, 'sort_order' => 20],
            ['code' => 'wiring', 'operation_name' => $t('Cablaggio e quadro', 'Wiring and panel', 'Verdrahtung und Schaltschrank', 'Câblage et armoire'), 'work_center' => 'WC-ELE', 'setup_hours_formula' => '0.5', 'run_hours_formula' => '2 + cable_length_m * 0.04', 'hourly_rate' => 58, 'sort_order' => 30],
            ['code' => 'atex-certification', 'operation_name' => $t('Verifica e documentazione ATEX', 'ATEX verification and documentation', 'ATEX-Prüfung und Dokumentation', 'Vérification et documentation ATEX'), 'work_center' => 'WC-QLT', 'condition_expression' => "atex_zone != 'NONE'", 'setup_hours_formula' => '0', 'run_hours_formula' => '6', 'hourly_rate' => 95, 'sort_order' => 40],
            ['code' => 'testing', 'operation_name' => $t('Collaudo idraulico', 'Hydraulic testing', 'Hydraulische Prüfung', 'Essai hydraulique'), 'work_center' => 'WC-TST', 'setup_hours_formula' => '0.5', 'run_hours_formula' => 'attr.test_level.hours', 'hourly_rate' => 70, 'sort_order' => 50],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pricing(): array
    {
        $t = CpqText::t(...);

        return [
            ['code' => 'ch-certification-cost', 'label' => $t('Costo certificazione svizzera', 'Swiss certification cost', 'Kosten Schweizer Zertifizierung', 'Coût de certification suisse'), 'effect' => 'add_cost', 'condition_expression' => "destination_country == 'CH'", 'formula' => '120', 'sort_order' => 5],
            ['code' => 'cost-plus', 'label' => $t('Prezzo a margine obiettivo 32%', 'Price at 32% target margin', 'Preis bei 32 % Zielmarge', 'Prix à marge cible de 32 %'), 'effect' => 'set_price', 'formula' => 'cost_subtotal / (1 - 0.32)', 'sort_order' => 10],
            ['code' => 'atex-surcharge', 'label' => $t('Maggiorazione ATEX', 'ATEX surcharge', 'ATEX-Aufschlag', 'Majoration ATEX'), 'effect' => 'add_price', 'condition_expression' => "atex_zone != 'NONE'", 'formula' => "if(atex_zone == 'ZONE_1', 2200, 1500)", 'sort_order' => 20],
            ['code' => 'ch-compliance-fee', 'label' => $t('Conformità SUVA/ESTI', 'SUVA/ESTI compliance', 'SUVA/ESTI-Konformität', 'Conformité SUVA/ESTI'), 'effect' => 'add_price', 'condition_expression' => "destination_country == 'CH'", 'formula' => '350', 'sort_order' => 30],
            ['code' => 'warranty-extension', 'label' => $t('Estensione di garanzia', 'Warranty extension', 'Garantieverlängerung', 'Extension de garantie'), 'effect' => 'add_price', 'condition_expression' => 'warranty_years > 2', 'formula' => 'list_subtotal * 0.03 * (warranty_years - 2)', 'sort_order' => 40],
            ['code' => 'volume-discount', 'label' => $t('Sconto quantità', 'Volume discount', 'Mengenrabatt', 'Remise de quantité'), 'effect' => 'discount_percent', 'formula' => 'if(quantity >= 10, 5, if(quantity >= 5, 3, 0))', 'sort_order' => 90],
        ];
    }
}
