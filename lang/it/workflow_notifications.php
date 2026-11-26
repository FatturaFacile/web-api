<?php

declare(strict_types=1);

return [
    'request' => ['title' => 'Approvazione richiesta', 'body' => ':type :label attende la tua decisione. Scadenza: :due. Apri il link per esaminarla.'],
    'reminder' => ['title' => 'Promemoria di approvazione', 'body' => ':type :label attende ancora la tua decisione (scadenza :due).'],
    'escalation' => ['title' => 'Approvazione scalata a te', 'body' => ':type :label non è stata decisa in tempo e ora richiede la tua decisione.'],
    'approved' => ['title' => 'Richiesta approvata', 'body' => 'La tua richiesta (:type :label) è stata approvata.'],
    'rejected' => ['title' => 'Richiesta respinta', 'body' => 'La tua richiesta (:type :label) è stata respinta. Apri il link per vedere il motivo.'],
    'changes_requested' => ['title' => 'Modifiche richieste', 'body' => 'Un approvatore ha chiesto modifiche alla tua richiesta (:type :label). Apri il link per vedere il motivo.'],
    'expired' => ['title' => 'Approvazione scaduta', 'body' => 'Nessuno ha deciso in tempo sulla tua richiesta (:type :label). È stata chiusa.'],
    'withdrawn' => ['title' => 'Richiesta ritirata', 'body' => 'Il richiedente ha ritirato la richiesta (:type :label). Non resta nulla da decidere.'],
    'blocked' => ['title' => 'Approvazione bloccata', 'body' => 'L\'approvazione (:type :label) non può proseguire perché non si trova alcun approvatore. Assegna un approvatore oppure annullala.'],
    'delegation' => ['title' => 'Delega attivata', 'body' => 'Sostituisci un collega dal :from al :to. Le sue approvazioni compaiono nella tua casella.'],
    'subject' => [
        'sales_quote' => 'Il preventivo',
        'purchase_request' => 'La richiesta d\'acquisto',
        'purchase_order' => 'L\'ordine fornitore',
        'supplier_invoice' => 'La fattura fornitore',
        'expense_report' => 'La nota spese',
        'leave_request' => 'La richiesta di assenza',
        'payment_batch' => 'Il lotto di pagamento',
        'supplier_master' => 'L\'anagrafica fornitore',
        'dms_document' => 'Il documento',
        'document' => 'Il documento',
    ],
];
