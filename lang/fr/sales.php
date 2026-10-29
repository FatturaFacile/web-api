<?php

declare(strict_types=1);

return [
    'order_already_invoiced' => 'Cette commande a déjà été facturée.',
    'order_cancelled' => 'Une commande annulée ne peut pas être facturée.',
    'deposit_line' => 'Acompte pour commande :number (:percentage%)',
    'deposit_deduction' => 'Moins : facture d\'acompte :number',
    'deposit_exceeds_order' => 'Le total des acomptes ne peut pas dépasser le total de la commande.',
    'delivery_note_already_invoiced' => 'Ce bon de livraison a déjà été facturé.',

    'quote_not_convertible' => 'Un devis rejeté ou expiré ne peut pas être converti.',
    'quote_already_invoiced' => 'Ce devis a déjà été facturé.',
    'quote_already_signed' => 'Ce devis a déjà été signé.',
    'quote_not_signable' => 'Un devis rejeté ou expiré ne peut pas être signé.',

    'quote_already_converted' => 'Ce devis a déjà été converti en commande.',
    'order_not_deliverable' => 'Seules les commandes confirmées peuvent être livrées.',
    'nothing_to_deliver' => 'Rien de restant à livrer.',
    'proforma_only' => 'Seules les factures proforma peuvent être converties.',
    'proforma_already_converted' => 'Cette facture proforma a déjà été convertie.',
    'payment_on_proforma' => 'Les paiements ne peuvent pas être enregistrés sur une proforma.',
    'payment_exceeds_balance' => 'Le paiement dépasse le solde dû.',
    'import_line_matched' => 'Cette ligne est déjà rapprochée.',

    'signature_invalid' => 'La signature doit être une image PNG ne dépassant pas :max Ko.',
    'reminder_not_payable' => 'Seules les factures échues et non payées peuvent être relancées.',
    'reminder_no_email' => 'Le contact ne possède pas d\'adresse e-mail.',
    'bank_import_note' => 'Importation bancaire : :detail',

    'messages' => [
        'quotes_list' => 'Devis récupérés avec succès',
        'quote_created' => 'Devis créé avec succès',
        'quote_retrieved' => 'Devis récupéré avec succès',
        'quote_updated' => 'Devis mis à jour avec succès',
        'quote_deleted' => 'Devis supprimé avec succès',
        'quote_to_order' => 'Devis converti en commande',
        'quote_submitted' => 'Devis soumis pour approbation',
        'order_created' => 'Bon de commande client créé avec succès',
        'order_confirmed' => 'Commande confirmée avec succès',
        'order_hold_placed' => 'Blocage de crédit appliqué à la commande',
        'order_hold_released' => 'Blocage de crédit levé avec succès',
        'delivery_note_created' => 'Bon de livraison créé avec succès',
        'goods_dispatched' => 'Marchandises expédiées avec succès',
        'invoice_created' => 'Facture créée avec succès',
        'invoice_posted' => 'Facture comptabilisée avec succès',
        'credit_note_created' => 'Avoir émis avec succès',
        'credit_applied' => 'Avoir imputé avec succès',
    ],
];
