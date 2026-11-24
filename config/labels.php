<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'max_quantity_per_item' => 500,
    'max_labels_per_request' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Default content options
    |--------------------------------------------------------------------------
    | Applied when neither the request nor the preset overrides them.
    */
    'defaults' => [
        'show_name' => true,
        'show_sku' => true,
        'show_price' => true,
        'price_includes_tax' => true,
        'show_barcode' => true,
        'barcode_type' => 'code128', // ean13, code128 or qrcode
        'font_size_pt' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | System presets
    |--------------------------------------------------------------------------
    | All measures are in millimetres. A preset may carry its own `defaults` for the content options.
    | Tenants can add their own presets in the `labels` tenant preferences; system keys cannot be overridden.
    */
    'presets' => [

        'a4_3x8_65x33' => [
            'name' => 'A4 sheet, 3 x 8 labels (65 x 33.8 mm)',
            'page_width_mm' => 210,
            'page_height_mm' => 297,
            'margin_top_mm' => 13.3,
            'margin_right_mm' => 7.5,
            'margin_bottom_mm' => 13.3,
            'margin_left_mm' => 7.5,
            'rows' => 8,
            'cols' => 3,
            'label_width_mm' => 65,
            'label_height_mm' => 33.8,
            'gap_x_mm' => 0,
            'gap_y_mm' => 0,
            'defaults' => ['font_size_pt' => 8],
        ],

        'a4_2x7_105x42' => [
            'name' => 'A4 sheet, 2 x 7 labels (105 x 42.4 mm)',
            'page_width_mm' => 210,
            'page_height_mm' => 297,
            'margin_top_mm' => 0.1,
            'margin_right_mm' => 0,
            'margin_bottom_mm' => 0.1,
            'margin_left_mm' => 0,
            'rows' => 7,
            'cols' => 2,
            'label_width_mm' => 105,
            'label_height_mm' => 42.4,
            'gap_x_mm' => 0,
            'gap_y_mm' => 0,
            'defaults' => ['font_size_pt' => 10],
        ],

        'thermal_50x30' => [
            'name' => 'Thermal roll label 50 x 30 mm',
            'page_width_mm' => 50,
            'page_height_mm' => 30,
            'margin_top_mm' => 0,
            'margin_right_mm' => 0,
            'margin_bottom_mm' => 0,
            'margin_left_mm' => 0,
            'rows' => 1,
            'cols' => 1,
            'label_width_mm' => 50,
            'label_height_mm' => 30,
            'gap_x_mm' => 0,
            'gap_y_mm' => 0,
            'defaults' => ['font_size_pt' => 7],
        ],

        'thermal_60x40' => [
            'name' => 'Thermal roll label 60 x 40 mm',
            'page_width_mm' => 60,
            'page_height_mm' => 40,
            'margin_top_mm' => 0,
            'margin_right_mm' => 0,
            'margin_bottom_mm' => 0,
            'margin_left_mm' => 0,
            'rows' => 1,
            'cols' => 1,
            'label_width_mm' => 60,
            'label_height_mm' => 40,
            'gap_x_mm' => 0,
            'gap_y_mm' => 0,
            'defaults' => ['font_size_pt' => 8],
        ],

    ],

];
