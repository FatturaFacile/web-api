<?php

declare(strict_types=1);

return [

    'errors' => [
        'not_dispatchable' => 'Si può assegnare solo un ordine di lavoro non pianificato, pianificato o già assegnato.',
        'window_invalid' => 'La fine dell\'intervento deve essere successiva all\'inizio.',
        'technician_unavailable' => 'Il tecnico non è disponibile.',
        'schedule_conflict' => 'Il tecnico ha già un intervento sovrapposto.',
        'skills_missing' => 'Al tecnico mancano le competenze richieste: :skills.',
        'no_van' => 'Il tecnico non ha un magazzino furgone attivo.',
        'van_stock_short' => 'Il magazzino furgone non contiene i ricambi richiesti da questo ordine di lavoro.',
        'parts_not_open' => 'I ricambi si possono consumare solo su un ordine di lavoro assegnato e non completato.',
        'report_not_open' => 'Il rapportino si può firmare solo su un ordine di lavoro assegnato, in viaggio o sul posto.',
        'report_exists' => 'Questo ordine di lavoro ha già un rapportino firmato.',
        'meter_regression' => 'La lettura del contatore è inferiore a quella già registrata per questa macchina.',
        'report_not_invoiceable' => 'Il rapportino deve essere firmato e l\'ordine di lavoro completato prima della fatturazione.',
        'report_already_invoiced' => 'Questo rapportino è già stato fatturato.',
        'nothing_billable' => 'Tutto il rapportino è coperto da contratto o garanzia: non c\'è nulla da fatturare.',
        'transition_invalid' => 'L\'ordine di lavoro non può passare da :from a :to.',
    ],

    'pdf' => [
        'title' => 'Rapportino d\'Intervento',
        'report_number' => 'Rapportino n.',
        'work_order' => 'Ordine di lavoro',
        'customer' => 'Cliente',
        'site' => 'Sito',
        'equipment' => 'Macchina',
        'technician' => 'Tecnico',
        'signed_at' => 'Firmato il',
        'work_performed' => 'Lavoro eseguito',
        'checklist' => 'Checklist',
        'result_pass' => 'OK',
        'result_fail' => 'KO',
        'result_na' => 'N/A',
        'meter_readings' => 'Letture contatori',
        'operating_hours' => 'Ore di funzionamento',
        'cycles' => 'Cicli',
        'charges' => 'Manodopera, trasferta e ricambi',
        'description' => 'Descrizione',
        'quantity' => 'Qtà',
        'unit_price' => 'Prezzo unit.',
        'amount' => 'Importo',
        'status' => 'Stato',
        'covered' => 'Coperto da contratto/garanzia',
        'billable' => 'Fatturabile',
        'kind_labor' => 'Manodopera (ore)',
        'kind_travel' => 'Tempo di viaggio (ore)',
        'kind_callout' => 'Diritto fisso di chiamata',
        'kind_part' => 'Ricambio',
        'travel_km' => 'Chilometri percorsi (km)',
        'total_billable' => 'Totale fatturabile',
        'total_covered' => 'Totale coperto',
        'signature' => 'Firma del cliente',
        'signed_by' => 'Firmato da',
        'integrity_hash' => 'Impronta di integrità (SHA-256)',
    ],

    'mail' => [
        'subject' => 'Rapportino d\'intervento :number',
        'body' => 'In allegato il rapportino d\'intervento firmato :number relativo all\'intervento eseguito presso la vostra sede.',
    ],

];
