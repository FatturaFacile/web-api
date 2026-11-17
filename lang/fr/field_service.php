<?php

declare(strict_types=1);

return [

    'errors' => [
        'not_dispatchable' => 'Seul un ordre de travail non planifié, planifié ou déjà attribué peut être dispatché.',
        'window_invalid' => 'La fin de l\'intervention doit être postérieure à son début.',
        'technician_unavailable' => 'Le technicien n\'est pas disponible.',
        'schedule_conflict' => 'Le technicien a déjà une intervention qui se chevauche.',
        'skills_missing' => 'Il manque au technicien les compétences requises : :skills.',
        'no_van' => 'Le technicien n\'a pas de dépôt véhicule actif.',
        'van_stock_short' => 'Le dépôt du véhicule ne contient pas les pièces de rechange requises par cet ordre de travail.',
        'parts_not_open' => 'Les pièces ne peuvent être consommées que sur un ordre de travail attribué et non terminé.',
        'report_not_open' => 'Le rapport ne peut être signé que sur un ordre de travail attribué, en route ou sur place.',
        'report_exists' => 'Cet ordre de travail a déjà un rapport d\'intervention signé.',
        'meter_regression' => 'Le relevé du compteur est inférieur à celui déjà enregistré pour cet équipement.',
        'report_not_invoiceable' => 'Le rapport doit être signé et l\'ordre de travail terminé avant la facturation.',
        'report_already_invoiced' => 'Ce rapport d\'intervention a déjà été facturé.',
        'nothing_billable' => 'Tout le rapport est couvert par le contrat ou la garantie : rien à facturer.',
        'transition_invalid' => 'L\'ordre de travail ne peut pas passer de :from à :to.',
    ],

    'pdf' => [
        'title' => 'Rapport d\'intervention',
        'report_number' => 'Rapport n°',
        'work_order' => 'Ordre de travail',
        'customer' => 'Client',
        'site' => 'Site',
        'equipment' => 'Équipement',
        'technician' => 'Technicien',
        'signed_at' => 'Signé le',
        'work_performed' => 'Travaux effectués',
        'checklist' => 'Liste de contrôle',
        'result_pass' => 'OK',
        'result_fail' => 'Non conforme',
        'result_na' => 'N/A',
        'meter_readings' => 'Relevés de compteurs',
        'operating_hours' => 'Heures de fonctionnement',
        'cycles' => 'Cycles',
        'charges' => 'Main-d\'œuvre, déplacement et pièces',
        'description' => 'Description',
        'quantity' => 'Qté',
        'unit_price' => 'Prix unitaire',
        'amount' => 'Montant',
        'status' => 'Statut',
        'covered' => 'Couvert par contrat/garantie',
        'billable' => 'Facturable',
        'kind_labor' => 'Main-d\'œuvre (heures)',
        'kind_travel' => 'Temps de trajet (heures)',
        'kind_callout' => 'Forfait de déplacement',
        'kind_part' => 'Pièce de rechange',
        'travel_km' => 'Distance parcourue (km)',
        'total_billable' => 'Total facturable',
        'total_covered' => 'Total couvert',
        'signature' => 'Signature du client',
        'signed_by' => 'Signé par',
        'integrity_hash' => 'Empreinte d\'intégrité (SHA-256)',
    ],

    'mail' => [
        'subject' => 'Rapport d\'intervention :number',
        'body' => 'Veuillez trouver ci-joint le rapport d\'intervention signé :number relatif à l\'intervention réalisée sur votre site.',
    ],

];
