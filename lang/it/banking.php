<?php

declare(strict_types=1);

return [

    'messages' => [
        'imported' => 'Estratto conto bancario importato con successo',
        'listed' => 'Estratti conto recuperati con successo',
        'retrieved' => 'Estratto conto recuperato con successo',
        'reconciled' => 'Riconciliazione bancaria completata con successo',
        'matched' => 'Movimento bancario abbinato con successo',
        'unmatched' => 'Abbinamento movimento bancario annullato con successo',
        'categorized' => 'Movimento bancario categorizzato con successo',
        'payment_batch_created' => 'Distinta di pagamento creata con successo',
        'payment_batch_executed' => 'Distinta di pagamento eseguita (XML ISO 20022 generato)',
        'collection_recorded' => 'Incasso registrato con successo',
    ],

    'errors' => [
        'duplicate_statement' => 'Questo estratto conto bancario è già stato importato.',
        'line_not_credit' => 'Solo i movimenti in entrata (avere) possono essere abbinati a fatture clienti.',
        'line_already_reconciled' => 'Il movimento è già riconciliato.',
        'line_not_reconciled' => 'Il movimento non è riconciliato, operazione non necessaria.',
        'invoice_not_payable' => 'La fattura non può ricevere questo pagamento: saldata o in altra valuta.',
        'overpayment' => 'L\'importo supera il saldo dovuto della fattura.',
        'document_direction_mismatch' => 'Le entrate si abbinano a fatture clienti, le uscite a fatture fornitore.',
        'category_not_for_credit' => 'I movimenti in entrata possono essere categorizzati solo come giroconti interni.',
        'supplier_payment_exceeds_balance' => 'Il pagamento supera il saldo dovuto al fornitore.',
        'difference_not_accepted' => 'L\'importo differisce dal saldo per :difference.',
        'ledger_account_not_configured' => 'Configura il conto :setting nelle preferenze bancarie (banking.ledger_defaults) prima di registrare questo movimento.',
        'ledger_account_unknown' => 'Il conto :code non è nel piano dei conti.',
        'bank_account_not_linked' => 'Il conto bancario :iban non è collegato a un conto del piano dei conti.',
        'posting_period_unavailable' => 'Il movimento non può essere registrato: :reason',
    ],

    'payment_notes' => [
        'payment' => 'Pagamento da estratto conto bancario',
        'bank_fee' => 'Commissioni bancarie trattenute alla fonte',
        'adjustment' => 'Rettifica per arrotondamento',
    ],

    'problem_titles' => [
        'import' => 'Importazione estratto conto bancario',
    ],

    'parse_errors' => [
        'unsupported_format' => 'Il file non è un estratto conto bancario supportato (CBI, CAMT.053 o CAMT.054).',
        'invalid_xml' => 'Il file non è un documento XML valido.',
        'unsafe_xml' => 'I documenti XML con DOCTYPE o dichiarazioni di entità non sono accettati.',
        'no_statement' => 'Il documento non contiene alcun estratto conto o notifica.',
        'invalid_line_length' => 'La riga :line ha :length caratteri invece di :expected.',
        'invalid_record' => 'La riga :line contiene un record :record non valido o inatteso.',
        'missing_header' => 'L\'estratto conto non ha un\'intestazione valida (IBAN del conto).',
        'missing_trailer' => 'L\'estratto conto è incompleto: manca il record di coda EF.',
        'record_count_mismatch' => 'Il record di coda dichiara :expected record ma ne sono stati letti :actual.',
        'empty_file' => 'Il file è vuoto.',
    ],

];
