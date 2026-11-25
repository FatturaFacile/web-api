<?php

declare(strict_types=1);

return [
    'reasons' => [
        'right_of_withdrawal' => 'Diritto di recesso / Reso commerciale',
        'commercial_return' => 'Reso commerciale / Accordo',
        'wrong_shipment' => 'Errata spedizione',
        'doa' => 'DOA (Dead On Arrival)',
        'warranty_defect' => 'Difetto in garanzia',
        'defective' => 'Difettoso o non funzionante',
        'damaged_in_transit' => 'Danneggiato durante il trasporto',
        'wrong_item' => 'Articolo errato',
        'not_as_described' => 'Non conforme alla descrizione',
        'changed_mind' => 'Ripensamento',
        'other' => 'Altro',
    ],
    'grades' => [
        'grade_a' => 'Grado A — Intatto (Rientro stock primario vendibile)',
        'grade_b' => 'Grado B — Scatola aperta / Lieve usura (Outlet B-Stock)',
        'grade_c' => 'Grado C — Ricondizionato / Usura visibile (Outlet B-Stock)',
        'scrap_or_supplier' => 'Da inviare a Fornitore per Credito o Rottamazione',
    ],
    'tax_rules' => [
        'it_art26_summary' => 'Art. 26, commi 2 e 3 DPR 633/72: variazione IVA in diminuzione ammessa entro 1 anno dall\'operazione originaria.',
        'it_art26_n2_2_explanation' => 'Oltre 1 anno per reso commerciale/accordo: emissione Nota di Credito TD04 per il solo imponibile fuori campo IVA (Natura N2.2) con <DatiFattureCollegate>.',
        'ch_art41_summary' => 'Art. 41 LIVA (CH): tracciabilità documentale della rettifica della controprestazione collegata alla fattura originaria.',
    ],
];
