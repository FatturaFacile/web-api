<?php

declare(strict_types=1);

return [

    'messages' => [
        'widgets_retrieved' => 'Available dashboard widgets retrieved successfully',
        'layout_retrieved' => 'Dashboard layout retrieved successfully',
        'layout_updated' => 'Dashboard layout saved successfully',
        'layout_reset' => 'Dashboard layout restored to the factory defaults',
        'data_retrieved' => 'Dashboard data retrieved successfully',
    ],

    'errors' => [
        'unknown_widget' => 'The dashboard widget ":widget" does not exist.',
        'exceeds_grid' => 'The widget does not fit in the :columns column grid.',
        'unknown_option' => 'The option ":option" is not available for this widget.',
        'invalid_option_value' => 'The option ":option" must be one of: :values.',
        'invalid_option_range' => 'The option ":option" must be an integer between :min and :max.',
        'widget_failed' => 'The widget data could not be calculated.',
    ],

    'widgets' => [
        'revenue_kpi' => [
            'title' => 'Revenue',
            'description' => 'Net invoiced amount of the selected period compared with the previous one.',
        ],
        'cash_flow_trend' => [
            'title' => 'Cash flow',
            'description' => 'Money in against money out over the latest months.',
        ],
        'overdue_invoices' => [
            'title' => 'Overdue invoices',
            'description' => 'Customer invoices past their due date, with the average delay.',
        ],
        'low_stock_alert' => [
            'title' => 'Low stock',
            'description' => 'Products and variants at or below their minimum stock.',
        ],
        'ecommerce_sync_status' => [
            'title' => 'E-commerce synchronisation',
            'description' => 'Imported and failed orders and the last synchronisation of every channel.',
        ],
        'top_selling_products' => [
            'title' => 'Top selling products',
            'description' => 'Best sellers of the current month.',
        ],
    ],

];
