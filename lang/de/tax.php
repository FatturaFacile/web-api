<?php

declare(strict_types=1);

return [
    'errors' => [
        'forfettario_incompatible' => 'Quellensteuer, Split Payment und Reverse Charge sind im Pauschalsystem (RF19) nicht zulässig.',
        'enasarco_rate_not_configured' => 'Für das Jahr :year ist kein ENASARCO-Satz konfiguriert.',
        'split_payment_incompatible' => 'Split Payment kann nicht mit Reverse Charge kombiniert werden.',
        'split_payment_requires_vat' => 'Split Payment erfordert mindestens eine Zeile mit MwSt.',
        'invalid_natura' => 'Der Natura-Code :natura ist ungültig.',
        'amount_out_of_range' => 'Ein Betrag ist zu gross für die Berechnung.',
        'invalid_quarter' => 'Das Quartal muss zwischen 1 und 4 liegen.',
    ],

    'warnings' => [
        'zero_rate_without_natura' => 'Zeile :line hat einen MwSt-Satz von 0% ohne Natura-Code.',
        'split_payment_customer' => 'Split Payment ist normalerweise öffentlichen Verwaltungen und börsennotierten Unternehmen vorbehalten; Kunde prüfen.',
    ],

    'messages' => [
        'preview' => 'Steuervorschau erfolgreich berechnet',
        'stamp_duty_report' => 'Stempelsteuerbericht erfolgreich abgerufen',
        'withholding_report' => 'Quellensteuerbericht erfolgreich abgerufen',
        'enasarco_status' => 'ENASARCO-Status erfolgreich abgerufen',
    ],
];
