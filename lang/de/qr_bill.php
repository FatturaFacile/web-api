<?php

declare(strict_types=1);

return [

    'additional_message' => 'Rechnung :number',

    'errors' => [
        'missing_tenant' => 'Die Rechnung hat keine ausstellende Firma.',
        'invalid_currency' => 'Die Schweizer QR-Rechnung unterstützt nur Rechnungen in CHF und EUR.',
        'invalid_amount' => 'Es gibt keinen offenen Betrag zu bezahlen.',
        'missing_creditor_iban' => 'Für die ausstellende Firma ist keine IBAN oder QR-IBAN konfiguriert.',
        'invalid_creditor_iban' => 'Der Gläubiger benötigt eine gültige Schweizer oder liechtensteinische IBAN oder QR-IBAN.',
        'missing_creditor_address' => 'Die Adresse der ausstellenden Firma ist unvollständig: Strasse, Postleitzahl und Ort sind erforderlich.',
        'invalid_reference' => 'Die Zahlungsreferenz ist weder eine gültige QR-Referenz (27 Ziffern, Modulo 10) noch eine Gläubigerreferenz (ISO 11649).',
        'reference_mismatch' => 'Die Zahlungsreferenz passt nicht zum konfigurierten Konto: QR-Referenzen benötigen eine QR-IBAN und Gläubigerreferenzen eine Standard-IBAN.',
        'validation_failed' => 'Die Daten der QR-Rechnung entsprechen nicht der SIX-Spezifikation.',
    ],

    'messages' => [
        'retrieved' => 'Schweizer QR-Rechnung erfolgreich erzeugt',
    ],

    'problem_title' => 'Schweizer QR-Rechnung nicht verfügbar',

];
