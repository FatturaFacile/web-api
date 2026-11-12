<?php

declare(strict_types=1);

return [

    'additional_message' => 'Facture :number',

    'errors' => [
        'missing_tenant' => "La facture n'a pas d'entreprise émettrice.",
        'invalid_currency' => 'La facture QR suisse ne prend en charge que les factures en CHF et EUR.',
        'invalid_amount' => "Il n'y a aucun montant restant à payer.",
        'missing_creditor_iban' => "Aucun IBAN ou QR-IBAN n'est configuré pour l'entreprise émettrice.",
        'invalid_creditor_iban' => 'Le créancier doit avoir un IBAN ou QR-IBAN suisse ou liechtensteinois valide.',
        'missing_creditor_address' => "L'adresse de l'entreprise émettrice est incomplète : la rue, le code postal et la ville sont obligatoires.",
        'invalid_reference' => "La référence de paiement n'est ni une référence QR valide (27 chiffres, modulo 10) ni une référence créancier (ISO 11649).",
        'reference_mismatch' => 'La référence de paiement ne correspond pas au compte configuré : les références QR nécessitent un QR-IBAN et les références créancier un IBAN standard.',
        'validation_failed' => 'Les données de la facture QR ne respectent pas la spécification SIX.',
    ],

    'messages' => [
        'retrieved' => 'Facture QR suisse générée avec succès',
    ],

    'problem_title' => 'Facture QR suisse indisponible',

];
