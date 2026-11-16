<?php

declare(strict_types=1);

return [
    'customer' => [
        'missing' => 'Il cliente non esiste in questa azienda.',
    ],
    'scorecard' => [
        'already_configured' => 'L\'azienda ha già fattori di scorecard o fasce di rating: modificali invece di creare quelli predefiniti.',
        'missing_factors' => 'La scorecard non ha fattori attivi: aggiungi almeno un fattore prima di calcolare il punteggio.',
        'no_band' => 'Nessuna fascia di rating contiene il punteggio :score: correggi gli intervalli delle fasce.',
        'manual_missing' => 'Inserisci un valore da 0 a 100 per il fattore manuale :code.',
    ],
    'band' => [
        'overlap' => 'L\'intervallo di punteggio si sovrappone a un\'altra fascia di rating.',
        'code_taken' => 'Un\'altra fascia di rating usa già questo codice.',
    ],
    'score' => [
        'missing' => 'Il cliente non ha ancora un punteggio: calcolalo prima di chiedere una proposta.',
    ],
    'limit' => [
        'pending_exists' => 'Il cliente ha già una richiesta di fido in attesa di decisione.',
        'amount_missing' => 'Inserisci il fido richiesto: non c\'è un punteggio da cui proporlo.',
        'not_pending' => 'Si può decidere o annullare solo una richiesta in attesa.',
        'same_user' => 'Chi ha fatto la richiesta non può deciderla.',
        'note_required' => 'Il fido supera la proposta di :proposed: motiva la decisione in una nota.',
        'valid_until_past' => 'Il fido non può essere valido fino a un giorno passato.',
    ],
    'insurance' => [
        'dates' => 'La polizza deve finire dopo il suo inizio.',
        'policy_not_active' => 'La polizza non è in vigore oggi.',
        'period' => 'La copertura non può durare oltre la fine della polizza.',
        'duplicate' => 'Il cliente ha già una copertura richiesta o concessa su questa polizza.',
        'total_exceeded' => 'Le coperture concesse su questa polizza (:granted) supererebbero la copertura totale.',
        'policy_has_covers' => 'La polizza ha delle coperture: annullala invece di eliminarla.',
    ],
    'strategy' => [
        'days_order' => 'I giorni dopo la scadenza non possono essere inferiori a quelli del passo precedente.',
        'in_use' => 'Un caso aperto sta già lavorando con i passi di questa strategia.',
        'default_required' => 'Imposta invece un\'altra strategia come predefinita.',
    ],
    'case' => [
        'no_overdue' => 'Il cliente non ha fatture scadute da recuperare.',
        'invoice_not_overdue' => 'Alcune fatture non sono fatture aperte e scadute del cliente.',
        'invoice_in_case' => 'Alcune fatture sono già in un caso di recupero aperto.',
        'closed' => 'Il caso è chiuso.',
        'disputed' => 'Il caso ha una contestazione aperta: decidila prima.',
        'promise_pending' => 'Il caso ha una promessa di pagamento in attesa: risolvila prima.',
        'no_strategy' => 'Il caso non ha una strategia di sollecito attiva.',
        'no_more_steps' => 'La strategia non ha altri passi.',
        'step_not_due' => 'Il prossimo passo scatta :days giorni dopo la scadenza: la fattura più vecchia è scaduta da :overdue giorni.',
        'open_disputes' => 'Il caso ha contestazioni aperte: decidile prima di chiuderlo.',
        'still_outstanding' => 'Il caso non può essere risolto: restano :amount da incassare.',
    ],
    'promise' => [
        'pending_exists' => 'Il caso ha già una promessa di pagamento in attesa.',
        'past_date' => 'La data promessa non può essere nel passato.',
        'amount_exceeds' => 'L\'importo promesso supera l\'importo da incassare di :outstanding.',
        'not_pending' => 'La promessa è già chiusa.',
        'paid_required' => 'Inserisci l\'importo pagato per segnare la promessa come mantenuta.',
        'paid_below' => 'L\'importo pagato è inferiore al promesso :promised: segna la promessa come non mantenuta.',
    ],
    'dispute' => [
        'invoice_not_open' => 'La fattura non è una fattura aperta di questo cliente.',
        'amount_exceeds' => 'L\'importo contestato supera quanto resta da incassare sulla fattura (:outstanding).',
        'case_mismatch' => 'Il caso non è un caso aperto di questo cliente.',
        'not_open' => 'La contestazione è già decisa o non è aperta.',
        'credit_required' => 'Inserisci l\'importo accreditato al cliente.',
        'credit_exceeds' => 'L\'importo accreditato supera il contestato di :disputed.',
    ],
];
