<?php

declare(strict_types=1);

return [
    'attachment' => [
        'file_missing' => 'Il file di questo allegato manca nell\'archivio.',
    ],
    'link' => [
        'record_not_found' => 'Questo record non esiste.',
    ],
    'document' => [
        'checked_out' => 'Questo documento è in modifica: rilascialo o annulla prima il blocco in modifica.',
        'under_retention' => 'Questo documento è soggetto a conservazione fino al :date.',
        'not_checked_out' => 'Questo documento non è in modifica.',
        'checked_out_by_other' => 'Questo documento è in modifica da un altro utente.',
        'legal_hold' => 'Questo documento è sottoposto a blocco legale e non può essere modificato.',
    ],
    'approval' => [
        'template_inactive' => 'Il modello di approvazione non è attivo.',
        'template_without_steps' => 'Il modello di approvazione non ha passaggi.',
        'already_pending' => 'Questo documento ha già una richiesta di approvazione in corso.',
        'not_pending' => 'Questa richiesta di approvazione non è più in corso.',
        'not_approver' => 'Non sei l\'approvatore del passaggio corrente.',
        'cannot_cancel' => 'Solo chi ha fatto la richiesta o un amministratore può annullarla.',
        'template_in_use' => 'Il percorso di approvazione non può cambiare finché c\'è una richiesta in corso.',
    ],
    'preservation' => [
        'no_hash' => 'Un pacchetto di conservazione non ha un hash da concatenare.',
    ],
    'ocr' => [
        'unsupported' => 'Nessun motore OCR supporta questo formato.',
    ],
];
