<?php

declare(strict_types=1);

return [
    'agreement' => [
        'bonus_required' => 'Inserisci il target e l\'importo del premio fisso.',
        'contributions_required' => 'Aggiungi almeno un contributo prima di attivare l\'accordo.',
        'version_conflict' => 'L\'accordo è stato modificato da un altro utente. Ricaricalo e riprova.',
        'not_active' => 'L’accordo non è attivo.',
        'locked' => 'Di un accordo non più in bozza si possono modificare solo il nome, le note e la data di fine.',
        'period' => 'La data di fine non può precedere la data di inizio.',
        'not_draft' => 'Solo un accordo in bozza può essere attivato.',
        'tiers_required' => 'Aggiungi almeno uno scaglione prima di attivare l’accordo.',
        'delete_draft_only' => 'Si può eliminare solo un accordo in bozza senza accantonamenti.',
        'code_taken' => 'Il codice :code è già usato da un altro accordo.',
        'tiers_distinct' => 'Ogni scaglione richiede una soglia diversa.',
    ],
    'accrual' => [
        'date_outside' => 'La data deve rientrare nel periodo dell’accordo (da :from a :to).',
    ],
    'settlement' => [
        'purchase_only' => 'Le proposte di liquidazione sono disponibili solo per gli accordi con i fornitori (acquisti).',
        'invoice_required' => 'Scegli la fattura del fornitore su cui compensare il premio.',
        'invoice_not_found' => 'La fattura non esiste o non appartiene al fornitore dell\'accordo.',
        'insufficient_open_balance' => 'Il residuo aperto della fattura (:open) non copre il premio.',
        'invalid_transition' => 'Questo passaggio non è consentito per una proposta in stato :status.',
        'version_conflict' => 'La proposta è stata modificata da un altro utente. Ricaricala e riprova.',
        'vat_account_required' => 'Scegli il conto IVA su cui registrare l\'IVA del premio.',
        'charge_note' => 'Premio fornitore :name (:code)',
        'charge_line' => 'Premio fornitore :name, periodo dal :from al :to',
        'set_off_description' => 'Premio :code compensato con la fattura :invoice',
        'nothing_to_settle' => 'Non ci sono premi maturati da liquidare.',
        'tax_rate_required' => 'Indica l’aliquota IVA della nota di credito.',
        'credit_note_note' => 'Premio di fine anno :name (:code)',
        'credit_note_line' => 'Premio di fine anno :name, periodo dal :from al :to',
    ],
    'contribution' => [
        'agreement_closed' => 'L\'accordo è chiuso: non si possono più aggiungere contributi.',
        'locked' => 'Solo un contributo pianificato può essere modificato o rimosso.',
    ],
    'run' => [
        'purchase_only' => 'Il calcolo dei ratei è disponibile solo per gli accordi con i fornitori (acquisti).',
        'period_locked' => 'Il periodo è bloccato: un calcolo dei ratei lo copre già fino al :date. Storna prima quel calcolo.',
        'not_posted' => 'Si può stornare solo un calcolo dei ratei registrato.',
        'not_latest' => 'Si può stornare solo l\'ultimo calcolo dei ratei dell\'accordo.',
        'already_settled' => 'I ratei di questo calcolo fanno già parte di una proposta di liquidazione.',
        'journal_description' => 'Rateo premio fornitore :code fino al :to',
    ],
    'promotion' => [
        'percentage_max' => 'Uno sconto percentuale non può superare 100.',
        'period' => 'La data di fine non può precedere la data di inizio.',
        'code_taken' => 'Il codice :code è già usato da un’altra promozione.',
    ],
];
