<?php

declare(strict_types=1);

namespace Database\Seeders\Cpq;

/**
 * Demo model of the CPQ configurator (Mod 230): a modular electrical distribution panel with stainless or painted
 * enclosure, a number of feeders, the main breaker and the Swiss ESTI conformity pack.
 */
final class ElectricalPanelModel
{
    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $t = CpqText::t(...);

        return [
            'code' => 'ELEC-PANEL',
            'name' => $t('Quadro elettrico di distribuzione modulare', 'Modular electrical distribution panel', 'Modulare Elektro-Verteilung', 'Tableau électrique de distribution modulaire'),
            'description' => $t('Quadro di distribuzione con carpenteria, interruttore generale e partenze configurabili.', 'Distribution panel with enclosure, main breaker and configurable feeders.', 'Verteiler mit Gehäuse, Hauptschalter und konfigurierbaren Abgängen.', 'Tableau de distribution avec enveloppe, disjoncteur général et départs configurables.'),
            'currency' => 'EUR',
            'base_price' => 0,
            'base_cost' => 120,
            'base_lead_time_days' => 5,
            'target_margin_percent' => 30,
            'max_discount_percent' => 12,
            'part_number_prefix' => 'EPN',
            'steps' => [
                ['code' => 'enclosure', 'title' => $t('Carpenteria', 'Enclosure', 'Gehäuse', 'Enveloppe'), 'description' => $t('Materiale, grado di protezione e destinazione.', 'Material, protection rating and destination.', 'Werkstoff, Schutzart und Zielland.', 'Matériau, indice de protection et destination.')],
                ['code' => 'power', 'title' => $t('Potenza e partenze', 'Power and feeders', 'Leistung und Abgänge', 'Puissance et départs'), 'description' => $t('Interruttore generale e numero di partenze.', 'Main breaker and number of feeders.', 'Hauptschalter und Anzahl der Abgänge.', 'Disjoncteur général et nombre de départs.')],
            ],
            'visual_base' => ['shape' => 'body', 'color' => '#d9dee4', 'label' => $t('Quadro', 'Panel', 'Verteiler', 'Tableau'), 'scale' => ['characteristic' => 'feeders', 'min_factor' => 0.7, 'max_factor' => 1.4]],
            'proposal_texts' => [
                'validity_days' => 30,
                'title' => $t('Proposta tecnico-commerciale — quadro elettrico', 'Technical and commercial proposal — electrical panel', 'Technisch-kommerzielles Angebot — Elektroverteiler', 'Proposition technique et commerciale — tableau électrique'),
                'intro' => $t('Configurazione tecnica e condizioni commerciali del quadro richiesto.', 'Technical configuration and commercial terms of the requested panel.', 'Technische Konfiguration und kommerzielle Bedingungen des angefragten Verteilers.', 'Configuration technique et conditions commerciales du tableau demandé.'),
                'warranty' => $t('Garanzia di 24 mesi sui componenti e sulla carpenteria dalla consegna.', '24 months warranty on components and enclosure from delivery.', '24 Monate Garantie auf Komponenten und Gehäuse ab Lieferung.', 'Garantie de 24 mois sur les composants et l’enveloppe à compter de la livraison.'),
                'terms' => $t('Prezzi EXW, IVA esclusa. Pagamento a 30 giorni data fattura.', 'Prices EXW, VAT excluded. Payment 30 days from invoice date.', 'Preise EXW, exkl. MwSt. Zahlung 30 Tage ab Rechnungsdatum.', 'Prix EXW, hors TVA. Paiement à 30 jours date de facture.'),
                'delivery' => $t('Consegna dopo conferma d’ordine e approvazione dello schema unifilare.', 'Delivery after order confirmation and approval of the single-line diagram.', 'Lieferung nach Auftragsbestätigung und Freigabe des Übersichtsschemas.', 'Livraison après confirmation de commande et approbation du schéma unifilaire.'),
                'installation' => $t('Fissaggio a parete o a pavimento secondo il disegno di ingombro allegato.', 'Wall or floor mounting according to the attached dimension drawing.', 'Wand- oder Bodenmontage gemäß beiliegender Maßzeichnung.', 'Fixation murale ou au sol selon le plan d’encombrement joint.'),
                'dimensional_notes' => [
                    $t('Larghezza da 600 a 1.600 mm in funzione del numero di partenze.', 'Width from 600 to 1,600 mm depending on the number of feeders.', 'Breite von 600 bis 1.600 mm je nach Anzahl der Abgänge.', 'Largeur de 600 à 1 600 mm selon le nombre de départs.'),
                ],
            ],
            'characteristics' => [
                ['code' => 'enclosure_material', 'step_code' => 'enclosure', 'type' => 'list', 'label' => $t('Materiale carpenteria', 'Enclosure material', 'Gehäusewerkstoff', 'Matériau de l’enveloppe'), 'part_number' => ['position' => 1], 'sort_order' => 10, 'options' => [
                    ['code' => 'PAINTED', 'label' => $t('Lamiera verniciata', 'Painted sheet steel', 'Stahlblech lackiert', 'Tôle peinte'), 'part_number_code' => 'PT', 'is_default' => true, 'visual' => ['layer' => 'material', 'shape' => 'body_finish', 'color' => '#d4d9de', 'z' => 10]],
                    ['code' => 'AISI_304', 'label' => $t('Acciaio inox AISI 304', 'AISI 304 stainless steel', 'Edelstahl AISI 304', 'Acier inoxydable AISI 304'), 'part_number_code' => 'S4', 'visual' => ['layer' => 'material', 'shape' => 'body_finish', 'color' => '#c9d1d9', 'z' => 10]],
                    ['code' => 'AISI_316', 'label' => $t('Acciaio inox AISI 316', 'AISI 316 stainless steel', 'Edelstahl AISI 316', 'Acier inoxydable AISI 316'), 'part_number_code' => 'S6', 'visual' => ['layer' => 'material', 'shape' => 'body_finish', 'color' => '#9fb3c8', 'z' => 10]],
                ]],
                ['code' => 'ip_rating', 'step_code' => 'enclosure', 'type' => 'list', 'label' => $t('Grado di protezione', 'Protection rating', 'Schutzart', 'Indice de protection'), 'part_number' => ['position' => 2], 'sort_order' => 20, 'options' => [
                    ['code' => 'IP54', 'label' => $t('IP54', 'IP54', 'IP54', 'IP54'), 'part_number_code' => '54', 'is_default' => true],
                    ['code' => 'IP65', 'label' => $t('IP65', 'IP65', 'IP65', 'IP65'), 'part_number_code' => '65'],
                    ['code' => 'IP66', 'label' => $t('IP66', 'IP66', 'IP66', 'IP66'), 'part_number_code' => '66'],
                ]],
                ['code' => 'destination_country', 'step_code' => 'enclosure', 'type' => 'list', 'label' => $t('Paese di destinazione', 'Destination country', 'Zielland', 'Pays de destination'), 'part_number' => ['position' => 5], 'sort_order' => 30, 'options' => [
                    ['code' => 'IT', 'label' => $t('Italia', 'Italy', 'Italien', 'Italie'), 'part_number_code' => 'IT', 'is_default' => true],
                    ['code' => 'CH', 'label' => $t('Svizzera', 'Switzerland', 'Schweiz', 'Suisse'), 'part_number_code' => 'CH'],
                ]],
                ['code' => 'main_breaker_a', 'step_code' => 'power', 'type' => 'list', 'label' => $t('Interruttore generale', 'Main breaker', 'Hauptschalter', 'Disjoncteur général'), 'part_number' => ['position' => 3], 'sort_order' => 40, 'options' => [
                    ['code' => 'A63', 'label' => $t('63 A', '63 A', '63 A', '63 A'), 'part_number_code' => '063', 'is_default' => true, 'properties' => ['amps' => 63]],
                    ['code' => 'A160', 'label' => $t('160 A', '160 A', '160 A', '160 A'), 'part_number_code' => '160', 'properties' => ['amps' => 160]],
                    ['code' => 'A400', 'label' => $t('400 A', '400 A', '400 A', '400 A'), 'part_number_code' => '400', 'properties' => ['amps' => 400]],
                ]],
                ['code' => 'feeders', 'step_code' => 'power', 'type' => 'range', 'label' => $t('Numero di partenze', 'Number of feeders', 'Anzahl der Abgänge', 'Nombre de départs'), 'unit' => 'pcs', 'range_min' => 2, 'range_max' => 24, 'range_step' => 2, 'default_value' => '8', 'part_number' => ['position' => 4, 'prefix' => 'F'], 'sort_order' => 50],
                ['code' => 'esti_pack', 'step_code' => 'power', 'type' => 'boolean', 'label' => $t('Pacchetto conformità ESTI', 'ESTI conformity pack', 'ESTI-Konformitätspaket', 'Pack de conformité ESTI'), 'is_required' => false, 'default_value' => 'false', 'part_number' => ['position' => 6, 'true_code' => 'ESTI'], 'visual' => ['layer' => 'safety', 'shape' => 'kit', 'color' => '#fadb14', 'z' => 40], 'sort_order' => 60],
            ],
            'rules' => [
                ['code' => 'ch-esti', 'rule_type' => 'force_value', 'priority' => 10, 'condition_expression' => "destination_country == 'CH'", 'target_characteristic' => 'esti_pack', 'forced_value' => true, 'message' => $t('Per la Svizzera il pacchetto di conformità ESTI è obbligatorio e incluso automaticamente.', 'For Switzerland the ESTI conformity pack is mandatory and included automatically.', 'Für die Schweiz ist das ESTI-Konformitätspaket obligatorisch und automatisch eingeschlossen.', 'Pour la Suisse, le pack de conformité ESTI est obligatoire et inclus automatiquement.')],
                ['code' => 'painted-ip', 'rule_type' => 'exclude_options', 'priority' => 20, 'condition_expression' => "enclosure_material == 'PAINTED'", 'target_characteristic' => 'ip_rating', 'target_options' => ['IP66'], 'message' => $t('La lamiera verniciata non raggiunge IP66: scegliere acciaio inox.', 'Painted sheet steel cannot reach IP66: choose stainless steel.', 'Lackiertes Stahlblech erreicht IP66 nicht: Edelstahl wählen.', 'La tôle peinte n’atteint pas l’IP66 : choisir l’acier inoxydable.')],
                ['code' => 'big-breaker-feeders', 'rule_type' => 'limit_range', 'priority' => 30, 'condition_expression' => "main_breaker_a == 'A400'", 'target_characteristic' => 'feeders', 'params' => ['min' => 6], 'message' => $t('Con interruttore da 400 A servono almeno 6 partenze.', 'With a 400 A breaker at least 6 feeders are required.', 'Mit 400-A-Hauptschalter sind mindestens 6 Abgänge erforderlich.', 'Avec un disjoncteur de 400 A, au moins 6 départs sont requis.')],
            ],
            'bom' => [
                ['code' => 'enclosure-painted', 'item_code' => 'ENC-PAINT', 'item_name' => $t('Carpenteria in lamiera verniciata', 'Painted steel enclosure', 'Gehäuse Stahlblech lackiert', 'Enveloppe en tôle peinte'), 'condition_expression' => "enclosure_material == 'PAINTED'", 'quantity_formula' => '1', 'unit_cost_formula' => '180 + feeders * 14', 'lead_time_days' => 8, 'sort_order' => 10],
                ['code' => 'enclosure-304', 'item_code' => 'ENC-304', 'item_name' => $t('Carpenteria inox AISI 304', 'AISI 304 stainless enclosure', 'Edelstahlgehäuse AISI 304', 'Enveloppe inox AISI 304'), 'condition_expression' => "enclosure_material == 'AISI_304'", 'quantity_formula' => '1', 'unit_cost_formula' => '420 + feeders * 22', 'lead_time_days' => 12, 'sort_order' => 11],
                ['code' => 'enclosure-316', 'item_code' => 'ENC-316', 'item_name' => $t('Carpenteria inox AISI 316', 'AISI 316 stainless enclosure', 'Edelstahlgehäuse AISI 316', 'Enveloppe inox AISI 316'), 'condition_expression' => "enclosure_material == 'AISI_316'", 'quantity_formula' => '1', 'unit_cost_formula' => '560 + feeders * 28', 'lead_time_days' => 14, 'sort_order' => 12],
                ['code' => 'main-breaker', 'item_code' => 'BRK-MAIN', 'item_name' => $t('Interruttore generale', 'Main breaker', 'Hauptschalter', 'Disjoncteur général'), 'quantity_formula' => '1', 'unit_cost_formula' => 'attr.main_breaker_a.amps * 2.4 + 90', 'lead_time_days' => 6, 'sort_order' => 20],
                ['code' => 'feeder-breaker', 'item_code' => 'BRK-FEEDER', 'item_name' => $t('Interruttore di partenza', 'Feeder breaker', 'Abgangsschalter', 'Disjoncteur de départ'), 'quantity_formula' => 'feeders', 'unit_cost' => 38, 'lead_time_days' => 4, 'sort_order' => 30],
                ['code' => 'ip-seal', 'item_code' => 'SEAL-IP', 'item_name' => $t('Kit guarnizioni IP65/IP66', 'IP65/IP66 sealing kit', 'Dichtungssatz IP65/IP66', 'Kit d’étanchéité IP65/IP66'), 'condition_expression' => "ip_rating in ['IP65', 'IP66']", 'quantity_formula' => '1', 'unit_cost' => 55, 'lead_time_days' => 3, 'sort_order' => 40],
                ['code' => 'esti-pack', 'item_code' => 'PACK-ESTI', 'item_name' => $t('Pacchetto conformità ESTI', 'ESTI conformity pack', 'ESTI-Konformitätspaket', 'Pack de conformité ESTI'), 'condition_expression' => 'esti_pack', 'quantity_formula' => '1', 'unit_cost' => 260, 'lead_time_days' => 5, 'auto_include_message' => $t('Pacchetto ESTI richiesto dalla destinazione.', 'ESTI pack required by the destination.', 'ESTI-Paket durch das Zielland erforderlich.', 'Pack ESTI requis par la destination.'), 'sort_order' => 50],
            ],
            'routing' => [
                ['code' => 'assembly', 'operation_name' => $t('Montaggio carpenteria e apparecchi', 'Enclosure and device assembly', 'Montage Gehäuse und Geräte', 'Montage enveloppe et appareils'), 'work_center' => 'WC-ASM', 'setup_hours_formula' => '0.5', 'run_hours_formula' => '2 + feeders * 0.25', 'hourly_rate' => 52, 'sort_order' => 10],
                ['code' => 'testing', 'operation_name' => $t('Collaudo elettrico', 'Electrical testing', 'Elektrische Prüfung', 'Essai électrique'), 'work_center' => 'WC-TST', 'setup_hours_formula' => '0.25', 'run_hours_formula' => '1 + feeders * 0.05', 'hourly_rate' => 68, 'sort_order' => 20],
            ],
            'pricing' => [
                ['code' => 'cost-plus', 'label' => $t('Prezzo a margine obiettivo 30%', 'Price at 30% target margin', 'Preis bei 30 % Zielmarge', 'Prix à marge cible de 30 %'), 'effect' => 'set_price', 'formula' => 'cost_subtotal / (1 - 0.30)', 'sort_order' => 10],
                ['code' => 'ch-fee', 'label' => $t('Conformità ESTI', 'ESTI compliance', 'ESTI-Konformität', 'Conformité ESTI'), 'effect' => 'add_price', 'condition_expression' => "destination_country == 'CH'", 'formula' => '180', 'sort_order' => 20],
                ['code' => 'volume-discount', 'label' => $t('Sconto quantità', 'Volume discount', 'Mengenrabatt', 'Remise de quantité'), 'effect' => 'discount_percent', 'formula' => 'if(quantity >= 5, 4, 0)', 'sort_order' => 90],
            ],
        ];
    }
}
