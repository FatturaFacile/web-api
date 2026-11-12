<?php

declare(strict_types=1);

return [

    'messages' => [
        'unsupported_type' => "L'export PDF n'est pas disponible pour ce type de document.",
    ],

    'titles' => [
        'invoice' => 'Facture',
        'credit_note' => 'Note de crédit',
        'proforma' => 'Facture pro forma',
        'quote' => 'Devis',
        'sales_order' => 'Confirmation de commande',
        'delivery_note' => 'Bon de livraison',
        'work_order' => "Rapport d'intervention",
    ],

    'number' => 'N°',
    'date' => 'Date',
    'due_date' => 'Échéance',
    'valid_until' => "Valable jusqu'au",
    'customer' => 'Client',
    'vat_number' => 'N° de TVA',
    'fiscal_code' => 'Code fiscal',
    'uid_number' => 'IDE',
    'page' => 'Page',

    'columns' => [
        'description' => 'Description',
        'type' => 'Type',
        'quantity' => 'Qté',
        'unit_price' => 'Prix unitaire',
        'discount' => 'Rem. %',
        'tax' => 'TVA %',
        'total' => 'Total',
    ],

    'line_types' => [
        'labor' => "Main-d'œuvre",
        'material' => 'Matériel',
        'travel' => 'Déplacement',
    ],

    'totals' => [
        'net' => 'Total net',
        'tax' => 'TVA',
        'fund' => 'Cotisation à la caisse de prévoyance',
        'enasarco' => 'Cotisation Enasarco',
        'split_payment' => 'TVA versée par le client (split payment)',
        'due' => 'Montant à payer',
        'rounding' => 'Arrondi',
        'stamp_duty' => 'Droit de timbre',
        'withholding_tax' => 'Retenue à la source',
        'gross' => 'Total',
        'paid' => 'Payé',
        'balance' => 'Solde dû',
    ],

    'notes' => 'Notes',

    'legal' => [
        'forfettario' => "Opération effectuée en vertu de l'article 1, alinéas 54 à 89, de la loi n° 190/2014.",
        'declaration_of_intent' => "Non imposable en vertu de votre déclaration d'intention n° :protocol du :date.",
    ],

    'payment' => [
        'title' => 'Données de paiement',
        'method' => 'Mode de paiement',
        'terms' => 'Conditions de paiement',
        'bank' => 'Banque',
        'iban' => 'IBAN',
    ],

    'qr' => [
        'receipt' => 'Récépissé',
        'payment_part' => 'Section paiement',
        'account_payable_to' => 'Compte / Payable à',
        'reference' => 'Référence',
        'additional_information' => 'Informations supplémentaires',
        'payable_by' => 'Payable par',
        'payable_by_blank' => 'Payable par (nom/adresse)',
        'currency' => 'Monnaie',
        'amount' => 'Montant',
        'acceptance_point' => 'Point de dépôt',
        'unavailable' => "Le bulletin de versement QR suisse n'a pas pu être généré pour cette facture. Veuillez contacter l'émetteur pour obtenir les données de paiement.",
    ],

    'signature' => [
        'title' => 'Signature du client',
        'signed_by' => 'Signé par :name',
        'signed_on' => 'le :date',
        'line' => 'Date et signature',
    ],

    'quote' => [
        'terms' => 'Conditions générales',
        'acceptance' => 'Acceptation du devis',
    ],

    'delivery' => [
        'ship_to' => 'Lieu de destination',
        'transport' => 'Données de transport',
        'reason' => 'Motif du transport',
        'appearance' => 'Aspect des marchandises',
        'packages' => 'Colis',
        'weight' => 'Poids brut (kg)',
        'carrier' => 'Transporteur',
        'courier' => 'Coursier',
        'license_plate' => "Plaque d'immatriculation",
        'departure' => 'Départ',
        'receipt' => 'Marchandise reçue en bon état',
    ],

    'work_order' => [
        'technician' => 'Technicien',
        'address' => "Lieu d'intervention",
        'started' => 'Début',
        'ended' => 'Fin',
        'labor_hours' => 'Heures de travail',
        'acceptance' => 'Travail accepté par le client',
    ],

];
