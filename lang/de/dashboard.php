<?php

declare(strict_types=1);

return [

    'messages' => [
        'widgets_retrieved' => 'Verfügbare Dashboard-Widgets erfolgreich abgerufen',
        'layout_retrieved' => 'Dashboard-Layout erfolgreich abgerufen',
        'layout_updated' => 'Dashboard-Layout erfolgreich gespeichert',
        'layout_reset' => 'Dashboard-Layout auf die Werkseinstellungen zurückgesetzt',
        'data_retrieved' => 'Dashboard-Daten erfolgreich abgerufen',
    ],

    'errors' => [
        'unknown_widget' => 'Das Dashboard-Widget ":widget" existiert nicht.',
        'exceeds_grid' => 'Das Widget passt nicht in das Raster mit :columns Spalten.',
        'unknown_option' => 'Die Option ":option" ist für dieses Widget nicht verfügbar.',
        'invalid_option_value' => 'Die Option ":option" muss einen der folgenden Werte haben: :values.',
        'invalid_option_range' => 'Die Option ":option" muss eine ganze Zahl zwischen :min und :max sein.',
        'widget_failed' => 'Die Widget-Daten konnten nicht berechnet werden.',
    ],

    'widgets' => [
        'revenue_kpi' => [
            'title' => 'Umsatz',
            'description' => 'Netto fakturierter Betrag des gewählten Zeitraums im Vergleich zum vorherigen.',
        ],
        'cash_flow_trend' => [
            'title' => 'Cashflow',
            'description' => 'Einnahmen im Vergleich zu den Ausgaben der letzten Monate.',
        ],
        'overdue_invoices' => [
            'title' => 'Überfällige Rechnungen',
            'description' => 'Kundenrechnungen nach Fälligkeit, mit der durchschnittlichen Verspätung.',
        ],
        'low_stock_alert' => [
            'title' => 'Niedriger Bestand',
            'description' => 'Artikel und Varianten auf oder unter dem Mindestbestand.',
        ],
        'ecommerce_sync_status' => [
            'title' => 'E-Commerce-Synchronisierung',
            'description' => 'Importierte und fehlgeschlagene Bestellungen sowie letzte Synchronisierung jedes Kanals.',
        ],
        'top_selling_products' => [
            'title' => 'Meistverkaufte Artikel',
            'description' => 'Die Bestseller des laufenden Monats.',
        ],
    ],

];
