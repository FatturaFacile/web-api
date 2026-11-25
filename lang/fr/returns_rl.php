<?php

declare(strict_types=1);

return [
    'reasons' => [
        'right_of_withdrawal' => 'Droit de rétractation / Retour commercial',
        'commercial_return' => 'Retour commercial / Accord',
        'wrong_shipment' => 'Erreur d\'expédition',
        'doa' => 'DOA (Dead On Arrival)',
        'warranty_defect' => 'Défaut sous garantie',
        'defective' => 'Défectueux ou hors service',
        'damaged_in_transit' => 'Endommagé pendant le transport',
        'wrong_item' => 'Mauvais article livré',
        'not_as_described' => 'Non conforme à la description',
        'changed_mind' => 'Changement d\'avis',
        'other' => 'Autre',
    ],
    'grades' => [
        'grade_a' => 'Grade A — Intact (Retour en stock primaire vendable)',
        'grade_b' => 'Grade B — Boîte ouverte / Légère usure (Outlet B-Stock)',
        'grade_c' => 'Grade C — Reconditionné / Usure visible (Outlet B-Stock)',
        'scrap_or_supplier' => 'Mise au rebut ou retour fournisseur pour avoir',
    ],
    'tax_rules' => [
        'it_art26_summary' => 'Art. 26 al. 2-3 DPR 633/72 : réduction de TVA autorisée dans un délai d\'1 an à compter de l\'opération d\'origine.',
        'it_art26_n2_2_explanation' => 'Plus d\'1 an pour retour commercial : émission d\'avoir TD04 sur la base imposable seule sans récupération de TVA (Nature N2.2) avec <DatiFattureCollegate>.',
        'ch_art41_summary' => 'Art. 41 LTVA (CH) : traçabilité documentaire de la diminution de la contre-prestation liée à la facture d\'origine.',
    ],
];
