<?php

declare(strict_types=1);

return [
    'sheet' => [
        'summary' => 'Synthèse',
        'breakdown' => 'Détail',
        'versions' => 'Versions',
        'rolling' => 'Rolling forecast',
        'variance_account' => 'Écarts par compte',
        'variance_cost_center' => 'Écarts par centre de coûts',
        'volume_price' => 'Volume et prix',
        'rules' => 'Règles de dépense',
        'commitments' => 'Engagements ouverts',
        'overrides' => 'Dérogations',
    ],
    'field' => [
        'item' => 'Poste', 'value' => 'Valeur', 'version' => 'Version', 'fiscal_year' => 'Exercice', 'type' => 'Type', 'status' => 'Statut',
        'currency' => 'Devise', 'approved_at' => 'Approuvé le', 'through_period' => 'Jusqu\'à la période', 'budget' => 'Budget', 'actual' => 'Réalisé',
        'variance' => 'Écart', 'variance_percent' => 'Écart %', 'committed' => 'Engagements ouverts', 'available' => 'Budget disponible',
        'account' => 'Compte', 'account_name' => 'Nom du compte', 'cost_center' => 'Centre de coûts', 'project' => 'Projet', 'total' => 'Total',
        'scenario' => 'Scénario', 'revision' => 'Révision', 'reason' => 'Motif', 'period' => 'Période', 'forecast' => 'Prévision', 'code' => 'Code',
        'name' => 'Nom', 'traffic_light' => 'Feu tricolore', 'volume_variance' => 'Écart de volume', 'price_variance' => 'Écart de prix',
        'decomposable' => 'Décomposé', 'mode' => 'Mode', 'threshold' => 'Seuil %', 'stages' => 'Étapes', 'active' => 'Active', 'source' => 'Document',
        'number' => 'Numéro', 'date' => 'Date', 'amount' => 'Montant', 'open_amount' => 'Montant ouvert', 'scope' => 'Portée', 'requested' => 'Demandé',
        'approved' => 'Approuvé', 'decision' => 'Commentaire de décision', 'decided_at' => 'Décidé le', 'valid_until' => 'Valable jusqu\'au',
    ],
    'type' => ['budget' => 'Budget initial', 'revised' => 'Budget révisé', 'forecast' => 'Prévision', 'rolling_forecast' => 'Rolling forecast', 'scenario' => 'Scénario'],
    'status' => ['draft' => 'Brouillon', 'submitted' => 'Soumis', 'approved' => 'Approuvé', 'locked' => 'Verrouillé'],
    'scenario' => ['best_case' => 'Meilleur cas', 'worst_case' => 'Pire cas', 'custom' => 'Personnalisé'],
    'light' => ['green' => 'Vert', 'amber' => 'Orange', 'red' => 'Rouge'],
    'mode' => ['allow' => 'Autoriser', 'warning' => 'Avertissement', 'hard_block' => 'Blocage'],
    'source' => ['requisition' => 'Demande d\'achat', 'purchase_order' => 'Commande fournisseur'],
    'scope' => ['document' => 'Document', 'allowance' => 'Enveloppe temporaire'],
    'override_status' => ['pending' => 'En attente', 'approved' => 'Approuvée', 'rejected' => 'Rejetée', 'cancelled' => 'Annulée', 'consumed' => 'Utilisée'],
    'yes' => 'Oui',
    'no' => 'Non',
];
