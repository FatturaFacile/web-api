<?php

declare(strict_types=1);

return [

    'messages' => [
        'widgets_retrieved' => 'Widget della dashboard disponibili recuperati con successo',
        'layout_retrieved' => 'Layout della dashboard recuperato con successo',
        'layout_updated' => 'Layout della dashboard salvato con successo',
        'layout_reset' => 'Layout della dashboard ripristinato alle impostazioni predefinite',
        'data_retrieved' => 'Dati della dashboard recuperati con successo',
    ],

    'errors' => [
        'unknown_widget' => 'Il widget della dashboard ":widget" non esiste.',
        'exceeds_grid' => 'Il widget non rientra nella griglia a :columns colonne.',
        'unknown_option' => 'L\'opzione ":option" non è disponibile per questo widget.',
        'invalid_option_value' => 'L\'opzione ":option" deve essere uno tra: :values.',
        'invalid_option_range' => 'L\'opzione ":option" deve essere un intero compreso tra :min e :max.',
        'widget_failed' => 'Non è stato possibile calcolare i dati del widget.',
    ],

    'widgets' => [
        'revenue_kpi' => [
            'title' => 'Ricavi',
            'description' => 'Importo netto fatturato nel periodo selezionato a confronto con il precedente.',
        ],
        'cash_flow_trend' => [
            'title' => 'Flusso di cassa',
            'description' => 'Entrate a confronto con le uscite negli ultimi mesi.',
        ],
        'overdue_invoices' => [
            'title' => 'Fatture scadute',
            'description' => 'Fatture clienti oltre la scadenza, con il ritardo medio.',
        ],
        'low_stock_alert' => [
            'title' => 'Scorte basse',
            'description' => 'Articoli e varianti pari o inferiori alla scorta minima.',
        ],
        'ecommerce_sync_status' => [
            'title' => 'Sincronizzazione e-commerce',
            'description' => 'Ordini importati e falliti e ultima sincronizzazione di ogni canale.',
        ],
        'top_selling_products' => [
            'title' => 'Articoli più venduti',
            'description' => 'I più venduti del mese corrente.',
        ],
    ],

];
