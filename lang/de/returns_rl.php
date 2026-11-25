<?php

declare(strict_types=1);

return [
    'reasons' => [
        'right_of_withdrawal' => 'Widerrufsrecht / Handelsretoure',
        'commercial_return' => 'Handelsretoure / Vereinbarung',
        'wrong_shipment' => 'Falschlieferung',
        'doa' => 'DOA (Dead On Arrival)',
        'warranty_defect' => 'Garantiedefekt',
        'defective' => 'Defekt oder nicht funktionsfähig',
        'damaged_in_transit' => 'Transportschaden',
        'wrong_item' => 'Falscher Artikel geliefert',
        'not_as_described' => 'Nicht wie beschrieben',
        'changed_mind' => 'Meinungsänderung',
        'other' => 'Sonstiges',
    ],
    'grades' => [
        'grade_a' => 'Grad A — Intakt (Rückführung in Primärbestand)',
        'grade_b' => 'Grad B — Geöffnete Verpackung / Leichte Gebrauchsspuren (Outlet B-Ware)',
        'grade_c' => 'Grad C — Aufbereitet / Sichtbare Gebrauchsspuren (Outlet B-Ware)',
        'scrap_or_supplier' => 'Verschrottung oder Lieferantenretoure für Gutschrift',
    ],
    'tax_rules' => [
        'it_art26_summary' => 'Art. 26 Abs. 2-3 DPR 633/72: MWST-Minderung innerhalb von 1 Jahr ab dem ursprünglichen Umsatz zulässig.',
        'it_art26_n2_2_explanation' => 'Über 1 Jahr bei Handelsretoure: Gutschrift TD04 nur für die Bemessungsgrundlage ohne MWST-Rückforderung (Code N2.2) mit <DatiFattureCollegate>.',
        'ch_art41_summary' => 'Art. 41 MWSTG (CH): dokumentarische Nachvollziehbarkeit der Entgeltsminderung mit Bezug zur Originalrechnung.',
    ],
];
