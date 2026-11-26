<?php

declare(strict_types=1);

return [
    'evidence' => [
        'title' => 'Evidenza di approvazione',
        'document' => 'Documento', 'requester' => 'Richiedente', 'amount' => 'Importo (valuta funzionale)', 'workflow' => 'Flusso',
        'status' => 'Stato', 'started' => 'Avviato', 'completed' => 'Concluso', 'steps' => 'Passi di approvazione',
        'date' => 'Data', 'time' => 'Ora', 'identity' => 'Identità', 'node' => 'Passo', 'outcome' => 'Esito', 'channel' => 'Canale', 'reason' => 'Motivo',
        'chain_intact' => 'Traccia di audit: :count voci, catena di hash integra.',
        'chain_broken' => 'Traccia di audit: :count voci, la catena di hash NON è integra. Non fare affidamento su questa evidenza.',
        'neutrality' => 'Evidenza di controllo interno (CO art. 728a/728b, D.Lgs. 231/2001, L. 262/2005). Il documento registra chi ha deciso cosa, quando e tramite quale canale; non calcola imposte e non ha effetti fiscali.',
    ],
    'status' => [
        'pending' => 'In attesa', 'approved' => 'Approvato', 'rejected' => 'Respinto', 'skipped' => 'Saltato', 'escalated' => 'Scalato',
        'expired' => 'Scaduto', 'cancelled' => 'Annullato', 'changes_requested' => 'Modifiche richieste', 'reassigned' => 'Riassegnato',
        'timed_out' => 'Scaduto per tempo', 'delegated' => 'Delegato',
    ],
    'event' => [
        'instance_started' => 'Approvazione avviata', 'step_reassigned' => 'Passo riassegnato', 'step_reassigned_removed_approver' => 'Passo passato ad altri (approvatore uscito)',
        'instance_withdrawn' => 'Ritirata dal richiedente', 'instance_cancelled' => 'Annullata', 'instance_cancelled_by_admin' => 'Annullata da un amministratore',
        'instance_blocked' => 'Bloccata: nessun approvatore', 'changes_requested' => 'Modifiche richieste', 'approval_invalidated' => 'Approvazione invalidata da una modifica', 'instance_expired' => 'Scaduta',
    ],
    'instance' => [
        'pending' => 'In attesa', 'in_progress' => 'In corso', 'approved' => 'Approvata', 'rejected' => 'Respinta', 'cancelled' => 'Annullata',
        'withdrawn' => 'Ritirata', 'blocked' => 'Bloccata', 'expired' => 'Scaduta',
    ],
    'sla' => [
        'title' => 'Report SLA delle approvazioni', 'by_definition' => 'Per flusso', 'by_node' => 'Per passo', 'by_approver' => 'Per approvatore',
        'definition' => 'Flusso', 'node' => 'Passo', 'approver' => 'Approvatore', 'steps' => 'Passi', 'on_time' => 'In tempo', 'late' => 'In ritardo',
        'timed_out' => 'Scaduti', 'overdue' => 'In ritardo aperti', 'open' => 'Aperti', 'escalated' => 'Scalati', 'avg_hours' => 'Ore medie per decidere',
    ],
];
