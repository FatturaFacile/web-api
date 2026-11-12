<?php

declare(strict_types=1);

return [
    'consolidated.min_two' => 'Für eine Sammelrechnung sind mindestens zwei Serviceaufträge erforderlich.',
    'consolidated.not_found' => 'Ein oder mehrere Serviceaufträge wurden nicht gefunden.',
    'consolidated.same_customer' => 'Alle zusammengefassten Serviceaufträge müssen demselben Kunden/Kontakt gehören.',
    'consolidated.cancelled' => 'Der Serviceauftrag :number ist storniert und kann nicht fakturiert werden.',
    'consolidated.not_signed' => 'Der Serviceauftrag :number wurde vom Kunden nicht unterzeichnet.',
    'consolidated.already_invoiced' => 'Der Serviceauftrag :number wurde bereits fakturiert.',
    'consolidated.no_billable_lines' => 'Keiner der ausgewählten Serviceaufträge enthält fakturierbare Positionen.',
    'invoice.cancelled' => 'Stornierte Serviceaufträge können nicht fakturiert werden.',
    'invoice.not_signed' => 'Der Serviceauftrag muss vom Kunden unterzeichnet werden, bevor eine Rechnung erstellt werden kann.',
    'invoice.already_invoiced' => 'Dieser Serviceauftrag wurde bereits fakturiert.',
    'invoice.no_billable_lines' => 'Der Serviceauftrag enthält keine fakturierbaren Positionen.',
    'save.signed_locked' => 'Unterzeichnete Serviceaufträge können nicht geändert werden.',
    'save.invoiced_locked' => 'Fakturierte Serviceaufträge können nicht geändert werden.',
    'sign.already_signed' => 'Dieser Serviceauftrag ist bereits unterzeichnet.',
    'sign.invalid_signature' => 'Die Daten des PNG-Unterschriftsbilds sind ungültig.',
];
