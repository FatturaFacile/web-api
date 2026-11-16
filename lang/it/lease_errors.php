<?php

declare(strict_types=1);

return [
    'contract' => [
        'transition' => 'Il leasing è :status: non può diventare :target.',
        'only_draft_editable' => 'Il leasing è :status: si può modificare solo una bozza. Per cambiare un leasing attivo usa una rimisurazione.',
        'only_draft_deletable' => 'Il leasing è :status: si può eliminare solo una bozza.',
        'not_active' => 'Il leasing è :status: deve essere attivo.',
        'exempt_not_measured' => 'Un leasing a breve termine o di modesto valore con esenzione va a costo: non ha misurazione né piano.',
        'negative_rou' => 'Il diritto d\'uso non sarebbe positivo: gli incentivi ricevuti superano la passività del leasing.',
        'term_too_long' => 'La durata non può superare :max mesi.',
        'term_multiple' => 'La durata di :term mesi deve essere un numero intero di periodi di pagamento di :frequency mesi.',
        'extension_required' => 'Indica i mesi della proroga quando è ragionevolmente certo che verrà esercitata.',
        'purchase_option_required' => 'Un\'opzione di acquisto ragionevolmente certa richiede il prezzo e la vita utile del bene.',
        'useful_life_short' => 'La vita utile del bene non può essere inferiore alla durata del leasing.',
        'short_term_limit' => 'Un leasing a breve termine non può durare più di :max mesi né includere un\'opzione di acquisto ragionevolmente certa.',
        'low_value_required' => 'Indica il valore del bene nuovo per scegliere l\'esenzione per beni di modesto valore.',
        'low_value_limit' => 'Il bene vale :value :currency da nuovo: l\'esenzione per modesto valore ammette fino a :max :currency.',
    ],
    'remeasure' => [
        'effective_not_period_start' => 'La data di efficacia deve essere il primo giorno di un mese del piano.',
        'period_already_posted' => 'I periodi fino al :period sono già contabilizzati: la rimisurazione deve avere effetto dal periodo :next.',
        'rate_required' => 'Una modifica o una rivalutazione richiede il tasso di sconto rivisto.',
        'rate_not_allowed' => 'Una variazione di indice mantiene il tasso di sconto originale.',
        'payment_required' => 'Una variazione di indice richiede il nuovo importo del canone.',
        'term_fixed_for_index' => 'Una variazione di indice non cambia la durata residua.',
        'term_zero' => 'La durata residua deve essere di almeno un mese.',
        'term_multiple' => 'Il leasing deve terminare dopo un numero intero di periodi di pagamento di :frequency mesi.',
    ],
    'posting' => [
        'not_postable' => 'Il leasing è :status: si contabilizza solo un leasing attivo con un piano.',
    ],
];
