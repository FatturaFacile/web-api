<?php

declare(strict_types=1);

return [
    'errors' => [
        'not_found' => 'Autofacture :id introuvable.',
        'invalid_state_transition' => 'Transition d\'état invalide de :from vers :to.',
        'xml_validation_failed' => 'Échec de la validation XML FatturaPA : :details',
        'missing_supplier_data' => 'Données fiscales fournisseur manquantes : :field.',
        'missing_tenant_fiscal_data' => 'Données fiscales de l\'entreprise manquantes pour le XML : :field.',
        'duplicate_self_invoice' => 'Une autofacture active existe déjà pour la facture fournisseur :id.',
        'sdi_dispatch_failed' => 'Échec de l\'envoi au SDI : :reason',
        'fx_rate_unavailable' => 'Taux de change BCE indisponible pour :currency au :date.',
        'vat_reconciliation_failed' => 'Échec du rapprochement des registres TVA : :reason',
    ],
    'warnings' => [
        'customs_import_bill' => 'Déclaration douanière d\'importation présente : l\'autofacture TD19 n\'est pas requise.',
        'manual_type_override' => 'Type de document modifié manuellement de :suggested à :selected.',
        'san_marino_vat_charged' => 'Facture de Saint-Marin avec TVA italienne déjà facturée (TD28).',
        'foreign_type_with_it_supplier' => 'Le type :type requiert normalement un fournisseur étranger.',
        'td16_with_foreign_supplier' => 'TD16 est réservé à l\'autoliquidation nationale avec un fournisseur italien.',
    ],
];
