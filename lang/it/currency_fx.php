<?php

declare(strict_types=1);

/**
 * Messaggi del motore multi-valuta (Mod 003): le risposte dell'API e gli errori delle sue regole. Tenere it, de, fr ed en allineati.
 */
return [
    'messages' => [
        'listed' => 'Record recuperati con successo.',
        'resolved' => 'Tasso di cambio determinato.',
        'rate_stored' => 'Tasso di cambio salvato.',
        'sync_queued' => 'Sincronizzazione dei tassi di cambio in coda.',
        'exposure' => 'Esposizione valutaria calcolata.',
        'settings' => 'Impostazioni valutarie recuperate.',
        'settings_saved' => 'Impostazioni valutarie salvate.',
        'revaluation_simulated' => 'Rivalutazione dei cambi simulata.',
        'revaluation' => 'Rivalutazione dei cambi recuperata.',
        'revaluation_posted' => 'Rivalutazione dei cambi registrata.',
        'revaluation_reversed' => 'Rivalutazione dei cambi stornata.',
    ],
    'errors' => [
        'exchange_rate_missing' => 'Nessun tasso di cambio :base/:quote disponibile il :date né nei :days giorni precedenti: inserisci un tasso manuale o sincronizza i tassi.',
        'sync_already_running' => 'Una sincronizzazione :provider è già in coda o in esecuzione.',
        'same_currency' => 'La valuta base e quella di quotazione devono essere diverse.',
        'contract_reason_required' => 'Un tasso contrattuale richiede una motivazione.',
        'currency_in_use' => 'Queste valute hanno ancora partite aperte o saldi e non possono essere disattivate: :currencies.',
        'vat_method_not_allowed' => 'Questo metodo di tasso IVA non è ammesso per le società in :country.',
        'vat_method_locked' => 'Il metodo di tasso IVA va mantenuto per almeno un periodo d\'imposta prima di poterlo cambiare.',
        'account_not_found' => 'Il conto non esiste nel piano dei conti della società.',
        'nothing_to_revalue' => 'Non ci sono partite aperte né conti in valuta estera da rivalutare al :date.',
        'period_end_invalid' => 'La fine periodo non è una fine valida di un periodo :type.',
        'revaluation_not_found' => 'La rivalutazione dei cambi non esiste.',
        'revaluation_already_posted' => 'La rivalutazione dei cambi è già registrata.',
        'revaluation_already_reversed' => 'La rivalutazione dei cambi è già stornata.',
        'revaluation_not_posted' => 'Solo una rivalutazione dei cambi registrata può essere stornata.',
        'revaluation_period_conflict' => 'Le posizioni al :date sono già state rivalutate e registrate: storna prima quella rivalutazione.',
        'line_account_missing' => 'La riga di rivalutazione :reference non ha un conto contabile.',
        'reversal_date_invalid' => 'La data di storno deve essere successiva alla fine periodo (:date).',
        'idempotency_key_required' => 'L\'intestazione Idempotency-Key (da 8 a 100 caratteri) è obbligatoria per registrare una rivalutazione.',
    ],
];
