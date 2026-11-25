<?php

declare(strict_types=1);

return [
    'reasons' => [
        'right_of_withdrawal' => 'Right of withdrawal / Commercial return',
        'commercial_return' => 'Commercial return / Agreement',
        'wrong_shipment' => 'Wrong shipment',
        'doa' => 'DOA (Dead On Arrival)',
        'warranty_defect' => 'Warranty defect',
        'defective' => 'Defective or not working',
        'damaged_in_transit' => 'Damaged in transit',
        'wrong_item' => 'Wrong item delivered',
        'not_as_described' => 'Not as described',
        'changed_mind' => 'Changed mind',
        'other' => 'Other',
    ],
    'grades' => [
        'grade_a' => 'Grade A — Intact (Return to primary sellable stock)',
        'grade_b' => 'Grade B — Open box / Minor wear (Outlet B-Stock)',
        'grade_c' => 'Grade C — Refurbished / Visible wear (Outlet B-Stock)',
        'scrap_or_supplier' => 'Scrap or Return to Supplier for Credit',
    ],
    'tax_rules' => [
        'it_art26_summary' => 'Art. 26(2-3) DPR 633/72: downward VAT adjustment allowed within 1 year from the original transaction.',
        'it_art26_n2_2_explanation' => 'Over 1 year for commercial return/agreement: issue Credit Note TD04 for taxable base only without VAT recovery (Nature N2.2) with <DatiFattureCollegate>.',
        'ch_art41_summary' => 'Art. 41 LIVA (CH): documentary traceability of consideration reduction linked to the original invoice.',
    ],
];
