<?php

declare(strict_types=1);

return [

    'messages' => [
        'unsupported_type' => 'Der PDF-Export ist für diesen Dokumenttyp nicht verfügbar.',
    ],

    'titles' => [
        'invoice' => 'Rechnung',
        'credit_note' => 'Gutschrift',
        'proforma' => 'Proforma-Rechnung',
        'quote' => 'Kostenvoranschlag',
        'sales_order' => 'Auftragsbestätigung',
        'delivery_note' => 'Lieferschein',
        'work_order' => 'Arbeitsrapport',
    ],

    'number' => 'Nr.',
    'date' => 'Datum',
    'due_date' => 'Fälligkeit',
    'valid_until' => 'Gültig bis',
    'customer' => 'Kunde',
    'vat_number' => 'MWST-Nr.',
    'fiscal_code' => 'Steuernummer',
    'uid_number' => 'UID',
    'page' => 'Seite',

    'columns' => [
        'description' => 'Beschreibung',
        'type' => 'Art',
        'quantity' => 'Menge',
        'unit_price' => 'Einzelpreis',
        'discount' => 'Rab. %',
        'tax' => 'MWST %',
        'total' => 'Total',
    ],

    'line_types' => [
        'labor' => 'Arbeit',
        'material' => 'Material',
        'travel' => 'Anfahrt',
    ],

    'totals' => [
        'net' => 'Nettobetrag',
        'tax' => 'MWST',
        'fund' => 'Beitrag an die Vorsorgekasse',
        'enasarco' => 'Enasarco-Beitrag',
        'split_payment' => 'Vom Kunden abgeführte MWST (Split Payment)',
        'due' => 'Zahlbarer Betrag',
        'rounding' => 'Rundung',
        'stamp_duty' => 'Stempelsteuer',
        'withholding_tax' => 'Quellensteuer',
        'gross' => 'Total',
        'paid' => 'Bezahlt',
        'balance' => 'Offener Saldo',
    ],

    'notes' => 'Notizen',

    'legal' => [
        'forfettario' => 'Geschäft gemäss Artikel 1, Absätze 54 bis 89, des Gesetzes Nr. 190/2014.',
        'declaration_of_intent' => 'Nicht steuerbar gemäss Ihrer Absichtserklärung Nr. :protocol vom :date.',
    ],

    'payment' => [
        'title' => 'Zahlungsangaben',
        'method' => 'Zahlungsart',
        'terms' => 'Zahlungsbedingungen',
        'bank' => 'Bank',
        'iban' => 'IBAN',
    ],

    'qr' => [
        'receipt' => 'Empfangsschein',
        'payment_part' => 'Zahlteil',
        'account_payable_to' => 'Konto / Zahlbar an',
        'reference' => 'Referenz',
        'additional_information' => 'Zusätzliche Informationen',
        'payable_by' => 'Zahlbar durch',
        'payable_by_blank' => 'Zahlbar durch (Name/Adresse)',
        'currency' => 'Währung',
        'amount' => 'Betrag',
        'acceptance_point' => 'Annahmestelle',
        'unavailable' => 'Der Schweizer QR-Einzahlungsschein konnte für diese Rechnung nicht erzeugt werden. Bitte wende dich für die Zahlungsangaben an den Rechnungssteller.',
    ],

    'signature' => [
        'title' => 'Unterschrift des Kunden',
        'signed_by' => 'Unterzeichnet von :name',
        'signed_on' => 'am :date',
        'line' => 'Datum und Unterschrift',
    ],

    'quote' => [
        'terms' => 'Allgemeine Geschäftsbedingungen',
        'acceptance' => 'Annahme des Kostenvoranschlags',
    ],

    'delivery' => [
        'ship_to' => 'Lieferadresse',
        'transport' => 'Transportangaben',
        'reason' => 'Transportgrund',
        'appearance' => 'Erscheinungsbild der Waren',
        'packages' => 'Packstücke',
        'weight' => 'Bruttogewicht (kg)',
        'carrier' => 'Spediteur',
        'courier' => 'Kurier',
        'license_plate' => 'Kontrollschild',
        'departure' => 'Abgang',
        'receipt' => 'Ware in gutem Zustand erhalten',
    ],

    'work_order' => [
        'technician' => 'Techniker',
        'address' => 'Einsatzort',
        'started' => 'Beginn',
        'ended' => 'Ende',
        'labor_hours' => 'Arbeitsstunden',
        'acceptance' => 'Vom Kunden abgenommene Arbeit',
    ],

];
