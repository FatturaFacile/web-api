<?php

declare(strict_types=1);

return [

    'additional_message' => 'Rechnung :number',

    'errors' => [
        'invalid_qr_iban' => 'Die QR-IBAN ist ungültig: Sie muss eine Schweizer oder liechtensteinische IBAN mit einer Instituts-ID zwischen 30000 und 31999 sein.',
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

    'parse_errors' => [
        'qr_not_found' => 'Die Datei enthält keine lesbare Schweizer QR-Rechnung. Laden Sie ein PDF oder Bild hoch, in dem der QR-Code als Bitmap vorliegt.',
        'invalid_payload' => 'Der Inhalt ist keine Schweizer QR-Rechnung (SPC).',
        'unsupported_file' => 'Nicht unterstützte oder leere Datei.',
    ],

    'messages' => [
        'parsed' => 'Schweizer QR-Rechnung erfolgreich gelesen',
        'reference_checked' => 'Referenz geprüft',
        'retrieved' => 'Schweizer QR-Rechnung erfolgreich erzeugt',
    ],

    'problem_title' => 'Schweizer QR-Rechnung nicht verfügbar',

];
