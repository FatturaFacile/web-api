<?php

declare(strict_types=1);

return [
    'account' => [
        'type_locked' => 'Il conto :code ha già dei movimenti: il tipo e il saldo normale non possono cambiare. Crea un nuovo conto e riclassifica con una scrittura.',
        'has_postings' => 'Il conto :code ha dei movimenti: disattivalo (is_active = false) invece di eliminarlo.',
        'has_sub_accounts' => 'Il conto :code ha dei sottoconti: spostali o eliminali prima.',
        'is_default_posting' => 'Il conto :code è un conto di registrazione predefinito: scegline prima un altro nelle impostazioni della contabilità.',
        'parent_cycle' => 'Un conto non può essere collocato sotto se stesso o sotto uno dei suoi sottoconti.',
    ],
    'accrual' => [
        'cutoff_outside_coverage' => 'La data di chiusura deve cadere nel periodo di competenza, prima del suo ultimo giorno.',
        'nothing_to_post' => 'La quota alla data di chiusura è zero: nulla da registrare.',
        'income_account_type' => 'Per :type il conto di conto economico deve essere di tipo :types.',
        'balance_account_type' => 'Per :type il conto di stato patrimoniale deve essere di tipo :types.',
    ],
    'settings' => [
        'posting_account_type' => 'Il conto per [:role] deve essere un conto di tipo :types di questa azienda.',
        'fx_provision_account_type' => 'Il conto del fondo utili su cambi non realizzati deve essere di tipo :types di questa azienda.',
    ],
    'journal' => [
        'submit_only_draft' => 'Solo una bozza può essere inviata in approvazione; questa scrittura è [:status].',
        'approve_only_pending' => 'Solo una scrittura in attesa di approvazione può essere approvata; questa scrittura è [:status].',
        'four_eyes' => 'Principio dei quattro occhi: la scrittura deve essere approvata da una persona diversa da chi l\'ha scritta o inviata.',
        'reject_only_pending' => 'Solo una scrittura in attesa di approvazione può essere rifiutata; questa scrittura è [:status].',
        'needs_second_approval' => 'Questa scrittura richiede l\'approvazione di una seconda persona: inviala e falla approvare.',
        'account_not_in_chart' => 'Il conto non appartiene al piano dei conti di questa azienda.',
        'account_inactive' => 'Il conto :code non è attivo.',
        'account_automatic_only' => 'Il conto :code accetta solo registrazioni automatiche.',
        'cost_center_required' => 'Il conto :code richiede un centro di costo.',
    ],
];
