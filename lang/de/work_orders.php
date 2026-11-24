<?php

declare(strict_types=1);

return [

    'locked' => 'Ein unterzeichneter oder fakturierter Arbeitsrapport kann nicht mehr bearbeitet werden.',
    'already_signed' => 'Dieser Arbeitsrapport wurde bereits unterzeichnet.',
    'not_signable' => 'Nur Arbeitsrapporte im Entwurf oder abgeschlossene Arbeitsrapporte können unterzeichnet werden.',
    'not_invoiceable' => 'Nur abgeschlossene oder unterzeichnete Arbeitsrapporte können fakturiert werden.',
    'no_lines' => 'Ein Arbeitsrapport ohne Positionen kann nicht fakturiert werden.',
    'not_completable' => 'Nur Arbeitsrapporte im Entwurf können abgeschlossen werden.',
    'internal_not_invoiceable' => 'Ein interner Arbeitsauftrag hat keinen Kunden und kann nicht fakturiert werden.',
    'visit_requires_technician' => 'Ein Einsatz benötigt einen Techniker: Gib einen an oder weise zuerst den Arbeitsauftrag zu.',
    'part_unknown_product' => 'Kein Produkt der Firma hat diese Artikelnummer.',
    'part_not_open' => 'Ersatzteile können nur für einen geplanten, zugewiesenen oder laufenden Arbeitsauftrag verbraucht werden.',
    'part_quantity_positive' => 'Die Menge muss grösser als null sein.',
    'billing_not_completed' => 'Nur ein abgeschlossener Arbeitsauftrag kann fakturiert werden.',
    'billing_not_billable' => 'Dieser Arbeitsauftrag ist nicht fakturierbar.',
    'billing_already_invoiced' => 'Dieser Arbeitsauftrag wurde bereits fakturiert.',
    'billing_nothing_to_invoice' => 'Auf diesem Arbeitsauftrag gibt es nichts zu fakturieren.',

    'messages' => [
        'created' => 'Arbeitsrapport erfolgreich erstellt',
        'updated' => 'Arbeitsrapport erfolgreich aktualisiert',
        'retrieved' => 'Arbeitsrapport erfolgreich abgerufen',
        'completed' => 'Arbeitsrapport abgeschlossen',
        'signed' => 'Arbeitsrapport erfolgreich unterzeichnet',
        'invoiced' => 'Rechnung aus Arbeitsrapport erstellt',
    ],

];
