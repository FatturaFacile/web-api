<?php

declare(strict_types=1);

return [
    'quantity_positive' => 'Die Menge muss größer als null sein.',
    'transition' => 'Der Status kann nicht von :from auf :to wechseln.',
    'reason' => [
        'duplicate' => 'Der Grundcode :code existiert bereits.',
        'unknown' => 'Der Grundcode :code existiert nicht oder ist nicht aktiv.',
    ],
    'stock' => [
        'warehouse_required' => 'Für die Lagerbewegung ist ein Lager erforderlich und das Unternehmen hat keines.',
    ],
    'rma' => [
        'locked' => 'Eine Retoure kann nur geändert werden, solange sie angefordert ist.',
        'customer_unknown' => 'Der Kunde existiert im Unternehmen nicht.',
        'source_required' => 'Eine Rechnung oder ein Verkaufsauftrag des Kunden ist erforderlich.',
        'invoice_not_returnable' => 'Die Rechnung existiert nicht, gehört nicht dem Kunden oder kann nicht retourniert werden (Gutschriften und Proforma).',
        'order_not_returnable' => 'Der Verkaufsauftrag existiert nicht oder gehört nicht dem Kunden.',
        'window_expired' => 'Die Rückgabefrist von :days Tagen ab dem Belegdatum ist abgelaufen.',
        'override_not_allowed' => 'Nur ein Genehmiger kann eine Retoure nach der Rückgabefrist annehmen.',
        'line_source_required' => 'Jede Zeile muss die Rechnungs- oder Auftragszeile nennen, die sie zurückgibt.',
        'line_not_of_source' => 'Die Zeile gehört nicht zum Ursprungsbeleg der Retoure.',
        'quantity_exceeds' => 'Von ":line" können nur noch :available zurückgegeben werden.',
        'line_not_of_return' => 'Eine Zeile gehört nicht zu dieser Retoure.',
        'received_exceeds' => 'Die erhaltene Menge von ":line" ist negativ oder übersteigt die genehmigte Menge.',
        'nothing_received' => 'Mindestens eine Zeile muss erhalten werden.',
        'disposition_required' => 'Die Zeile ":line" braucht eine Verfügung.',
        'inspected_exceeds' => 'Die geprüfte Menge von ":line" muss positiv sein und darf die erhaltene Menge nicht übersteigen.',
        'supplier_required' => 'Die Zeile ":line" geht an einen Lieferanten zurück: Wählen Sie einen bestehenden Lieferanten.',
        'credit_note_reference_required' => 'Die Referenz des Gutschriftentwurfs ist erforderlich.',
        'stock_note' => 'Kundenretoure :number',
        'replacement_note' => 'Ersatz für Retoure :number',
    ],
    'warranty' => [
        'void_locked' => 'Eine ungültige Garantie kann nicht geändert werden.',
        'product_unknown' => 'Das Produkt existiert im Unternehmen nicht.',
        'period_invalid' => 'Die Garantie kann nicht enden, bevor sie beginnt.',
        'identifier_required' => 'Eine Seriennummer oder eine Chargennummer ist erforderlich.',
        'serial_taken' => 'Die Seriennummer :serial hat für dieses Produkt bereits eine aktive Garantie.',
    ],
    'claim' => [
        'warranty_unknown' => 'Die Garantie existiert im Unternehmen nicht.',
        'rma_mismatch' => 'Die Retoure existiert nicht oder gehört einem anderen Kunden als die Garantie.',
        'not_valid' => 'Die Garantie deckte das Reklamationsdatum nicht ab (:reason): Nur ein Genehmiger kann sie annehmen.',
        'status_expected' => 'Die Reklamation ist :status: erwartet :expected.',
    ],
    'rtv' => [
        'locked' => 'Nur eine Lieferantenretoure im Entwurf kann Lieferant, Zeilen und erwartete Gutschrift ändern.',
        'final' => 'Eine geschlossene oder stornierte Lieferantenretoure kann nicht geändert werden.',
        'no_lines' => 'Eine Lieferantenretoure braucht mindestens eine Zeile.',
        'supplier_unknown' => 'Der Lieferant existiert im Unternehmen nicht.',
        'product_unknown' => 'Das Produkt existiert im Unternehmen nicht.',
        'receipt_line_mismatch' => 'Die Wareneingangszeile existiert nicht oder gehört zu einem anderen Produkt.',
        'quantity_exceeds' => 'Von ":line" können nur noch :available zurückgegeben werden.',
        'stock_note' => 'Lieferantenretoure :number',
    ],
    'advance_replacement' => [
        'refurbished_not_found' => 'Aufbereiteter B-Ware-Artikel im Unternehmen nicht gefunden.',
        'not_overdue' => 'Die Vorab-Ersatzlieferung hat das SLA-Rückgabedatum noch nicht überschritten.',
        'outbound_note' => 'Vorab-Ersatzlieferung (Cross-Shipping) :number',
        'penalty_invoice_line' => 'Belastung nicht zurückgesendetes Defektgerät (:number — :product, fällig :due)',
        'penalty_invoice_note' => 'Automatische Strafrechnung für nicht zurückgesendete Vorab-Ersatzlieferung :number',
    ],
    'grading' => [
        'photos_required' => 'Mindestens ein Foto des eingegangenen Produkts ist an der Prüfstation zwingend erforderlich.',
        'restock_note' => 'Wiedereinlagerung Grad A aus Retourenprüfung :number',
        'rtv_note' => 'Eingang für Lieferantenretoure aus Prüfung :number',
        'bstock_note' => 'B-Ware / Aufbereiteter Zugang :sku',
    ],
    'credit_note' => [
        'original_invoice_required' => 'Für die steuerliche Prüfung und Gutschriftserstellung (TD04) ist eine Ursprungsrechnung erforderlich.',
        'already_issued' => 'Für diese Kundenretoure wurde bereits eine Gutschrift erstellt.',
        'art26_over_one_year' => 'Hinweis Art. 26 Abs. 2-3 DPR 633/72: Seit der Ursprungsrechnung :invoice vom :date sind :days Tage (über 1 Jahr) vergangen. Bei Warenrückgabe/Handelsvereinbarung ist kein Vorsteuerabzug zulässig: Es wird eine Gutschrift TD04 nur über die Bemessungsgrundlage mit Code N2.2 vorgeschlagen.',
        'art41_liva_notice' => 'Schweiz — Art. 41 MWSTG: Dokumentarische Nachvollziehbarkeit der Entgeltsminderung mit Bezug auf Originalrechnung :invoice sicherstellen.',
        'default_note' => 'Gutschrift für Retoure :rma zu Rechnung :invoice',
    ],
];
