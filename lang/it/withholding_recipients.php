<?php

declare(strict_types=1);

return [
    'messages' => [
        'saved' => 'Dati salvati.',
        'deleted' => 'Elemento eliminato.',
        'inl_generated' => 'Comunicazione preventiva INL generata.',
        'inl_sent' => 'Comunicazione INL registrata come inviata.',
        'payment_posted' => 'Pagamento registrato e F24 programmato.',
        'payments_posted' => 'Pagamenti registrati e F24 programmati.',
        'returns_generated' => 'Certificazioni annuali generate.',
    ],
    'errors' => [
        'inl_not_applicable' => 'Solo i lavoratori occasionali richiedono la comunicazione INL.',
        'inl_cancelled' => 'Una comunicazione annullata non può essere inviata.',
        'inl_notification_missing' => 'Pagamento bloccato: nessuna comunicazione preventiva INL inviata per questo lavoratore.',
        'inl_notification_late' => 'Pagamento bloccato: la comunicazione INL è stata inviata dopo l\'inizio della prestazione.',
        'inl_notification_expired' => 'Pagamento bloccato: la comunicazione INL non copre la data della prestazione.',
        'recipient_inactive' => 'Il percipiente non è attivo.',
        'service_after_payment' => 'La prestazione non può essere successiva al pagamento.',
        'net_not_positive' => 'L\'importo netto da pagare non è positivo.',
        'regime_locked' => 'Il regime non può cambiare dopo la registrazione di compensi.',
        'recipient_has_settlements' => 'Un percipiente con compensi registrati non può essere eliminato.',
        'certificate_not_applicable' => 'Solo gli agenti possono dichiarare la ritenuta ridotta.',
    ],
    'inl' => [
        'title' => 'Comunicazione preventiva di lavoro autonomo occasionale',
        'legal_basis' => 'Art. 14 D.Lgs. 81/2008 — da inviare all\'Ispettorato nazionale del lavoro (INL) prima dell\'inizio della prestazione.',
        'client' => 'Committente',
        'client_tax_id' => 'Codice fiscale del committente',
        'worker' => 'Prestatore',
        'worker_tax_id' => 'Codice fiscale del prestatore',
        'activity' => 'Attività',
        'place' => 'Luogo della prestazione',
        'start' => 'Data di inizio',
        'end' => 'Data di fine',
        'compensation' => 'Compenso presunto',
        'recipient' => 'Destinatario',
    ],
];
