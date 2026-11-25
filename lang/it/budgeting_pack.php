<?php

declare(strict_types=1);

return [
    'sheet' => [
        'summary' => 'Sintesi',
        'breakdown' => 'Dettaglio',
        'versions' => 'Versioni',
        'rolling' => 'Rolling forecast',
        'variance_account' => 'Scostamenti per conto',
        'variance_cost_center' => 'Scostamenti per centro',
        'volume_price' => 'Volume e prezzo',
        'rules' => 'Regole di spesa',
        'commitments' => 'Impegni aperti',
        'overrides' => 'Deroghe',
    ],
    'field' => [
        'item' => 'Voce', 'value' => 'Valore', 'version' => 'Versione', 'fiscal_year' => 'Esercizio', 'type' => 'Tipo', 'status' => 'Stato',
        'currency' => 'Valuta', 'approved_at' => 'Approvato il', 'through_period' => 'Fino al periodo', 'budget' => 'Budget', 'actual' => 'Consuntivo',
        'variance' => 'Scostamento', 'variance_percent' => 'Scostamento %', 'committed' => 'Impegni aperti', 'available' => 'Budget disponibile',
        'account' => 'Conto', 'account_name' => 'Nome del conto', 'cost_center' => 'Centro di costo', 'project' => 'Progetto', 'total' => 'Totale',
        'scenario' => 'Scenario', 'revision' => 'Revisione', 'reason' => 'Motivo', 'period' => 'Periodo', 'forecast' => 'Previsione', 'code' => 'Codice',
        'name' => 'Nome', 'traffic_light' => 'Semaforo', 'volume_variance' => 'Scostamento di volume', 'price_variance' => 'Scostamento di prezzo',
        'decomposable' => 'Scomposto', 'mode' => 'Modalità', 'threshold' => 'Soglia %', 'stages' => 'Fasi', 'active' => 'Attiva', 'source' => 'Documento',
        'number' => 'Numero', 'date' => 'Data', 'amount' => 'Importo', 'open_amount' => 'Importo aperto', 'scope' => 'Ambito', 'requested' => 'Richiesto',
        'approved' => 'Approvato', 'decision' => 'Commento della decisione', 'decided_at' => 'Deciso il', 'valid_until' => 'Valida fino al',
    ],
    'type' => ['budget' => 'Budget originale', 'revised' => 'Budget rivisto', 'forecast' => 'Previsione', 'rolling_forecast' => 'Rolling forecast', 'scenario' => 'Scenario'],
    'status' => ['draft' => 'Bozza', 'submitted' => 'Inviato', 'approved' => 'Approvato', 'locked' => 'Bloccato'],
    'scenario' => ['best_case' => 'Caso migliore', 'worst_case' => 'Caso peggiore', 'custom' => 'Personalizzato'],
    'light' => ['green' => 'Verde', 'amber' => 'Ambra', 'red' => 'Rosso'],
    'mode' => ['allow' => 'Consenti', 'warning' => 'Avviso', 'hard_block' => 'Blocco'],
    'source' => ['requisition' => 'Richiesta d\'acquisto', 'purchase_order' => 'Ordine fornitore'],
    'scope' => ['document' => 'Documento', 'allowance' => 'Plafond temporaneo'],
    'override_status' => ['pending' => 'In attesa', 'approved' => 'Approvata', 'rejected' => 'Rifiutata', 'cancelled' => 'Annullata', 'consumed' => 'Utilizzata'],
    'yes' => 'Sì',
    'no' => 'No',
];
