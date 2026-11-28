<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Elenco recuperato con successo.',
        'loaded' => 'Dato recuperato con successo.',
        'authorization_saved' => 'L’autorizzazione del deposito è stata salvata.',
        'authorization_deleted' => 'L’autorizzazione del deposito è stata eliminata.',
        'mrn_registered' => 'L’ingresso in deposito sotto MRN è stato registrato e la garanzia CGU è stata impegnata.',
        'discharge_simulated' => 'Simulazione dello scarico calcolata: nulla è stato registrato.',
        'discharge_executed' => 'Lo scarico dal deposito è stato registrato e la quota di garanzia è stata liberata.',
        'vat_inbound_registered' => 'Il carico è stato registrato nel registro del deposito IVA.',
        'extraction_previewed' => 'Anteprima dell’estrazione calcolata: nulla è stato registrato.',
        'extraction_emitted' => 'L’estrazione è stata registrata e l’autofattura elettronica è stata creata.',
        'reconciliation_done' => 'La riconciliazione AEO è stata eseguita.',
    ],

    'errors' => [
        'record_immutable' => 'Questa registrazione fa parte della pista di audit e non è modificabile né eliminabile.',
        'authorization_not_found' => 'Autorizzazione del deposito non trovata.',
        'authorization_inactive' => 'L’autorizzazione :code non è attiva o non è valida alla data dell’operazione.',
        'regime_not_customs_warehouse' => 'Questa autorizzazione è un deposito IVA: usa il registro Art. 50-bis.',
        'regime_not_vat_depot' => 'Questa autorizzazione non è un deposito IVA Art. 50-bis.',
        'declaration_type_not_allowed' => 'La dichiarazione :declaration non è ammessa per il regime :regime.',
        'invalid_mrn' => 'L’MRN deve avere 18 caratteri: anno (2 cifre), Paese (2 lettere) e 14 caratteri alfanumerici.',
        'invalid_discharge_mrn' => 'Indica l’MRN valido della dichiarazione di svincolo.',
        'invalid_taric' => 'Il codice TARIC deve avere 8 o 10 cifre.',
        'mrn_already_registered' => 'L’MRN :mrn voce :item è già registrato.',
        'cgu_ceiling_exceeded' => 'Il massimale della garanzia CGU sarebbe superato: disponibile :available :currency, richiesto :requested :currency.',
        'cgu_release_exceeds_utilized' => 'Lo svincolo supera la garanzia utilizzata.',
        'ceiling_below_utilized' => 'Il massimale non può essere inferiore alla garanzia già utilizzata (:utilized).',
        'authorization_in_use' => 'L’autorizzazione ha partite o garanzia impegnata e non può essere eliminata.',
        'quantity_must_be_positive' => 'La quantità deve essere maggiore di zero.',
        'insufficient_remaining_quantity' => 'La quantità residua in deposito è insufficiente: disponibile :available, richiesta :requested.',
        'insufficient_remaining_weight' => 'Il peso netto residuo è insufficiente per l’MRN :mrn.',
        'lot_not_found' => 'Partita MRN non trovata.',
        'discharge_before_entry' => 'La data di scarico precede l’ingresso dell’MRN :mrn.',
        'usual_handling_code_required' => 'Indica l’operazione di manipolazione usuale (Allegato 71-03 CDU).',
        'fifo_needs_product_and_quantity' => 'Lo scarico FIFO richiede prodotto e quantità.',
        'movement_not_inbound' => 'Il movimento non è un carico del deposito IVA.',
        'movement_not_extraction' => 'Il movimento non è un’estrazione dal deposito IVA.',
        'negative_value' => 'I valori non possono essere negativi.',
        'f24_required' => 'L’estrazione TD23 richiede riferimento e data del versamento F24.',
        'f24_not_allowed' => 'Il versamento F24 è previsto solo per le estrazioni TD23.',
        'insufficient_depot_stock' => 'La giacenza del deposito IVA è insufficiente: disponibile :available, richiesta :requested.',
        'self_contact_missing' => 'Manca l’anagrafica della tua azienda (stessa partita IVA) per emettere l’autofattura.',
        'unknown_dataset' => 'Esportazione non disponibile.',
    ],

    'invoice' => [
        'goods_line' => 'Estrazione da deposito IVA, lotto :lot, quantità :quantity',
        'services_line' => 'Servizi di magazzinaggio e lavorazione resi nel deposito IVA',
        'note' => 'Estrazione da deposito IVA (art. 50-bis DL 331/1993), base imponibile :base, documento :type.',
    ],
];
