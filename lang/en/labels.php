<?php

declare(strict_types=1);

return [

    'title' => 'Product labels',

    'fields' => [
        'sku' => 'SKU',
        'brand' => 'Brand',
    ],

    'price' => [
        'including_tax' => 'incl. VAT',
        'excluding_tax' => 'excl. VAT',
    ],

    'errors' => [
        'unknown_preset' => 'The label preset ":preset" does not exist.',
        'preset_required' => 'Choose a label preset or set a default preset in the label preferences.',
        'preset_key_reserved' => 'The preset key ":key" is reserved for a system preset.',
        'preset_key_invalid' => 'The preset key ":key" is invalid: use lowercase letters, digits and underscores (maximum 50 characters).',
        'layout_does_not_fit' => 'The label grid does not fit on the page: check page size, margins, spacing and label size.',
        'offset_too_large' => 'The start offset must be lower than the number of labels per page (:count).',
        'too_many_labels' => 'A single request can print at most :max labels.',
        'products_not_encodable' => 'Some products cannot be encoded with the selected barcode type.',
        'product_not_encodable' => 'Product ":sku" cannot be encoded: :reason',

        'barcode' => [
            'empty_value' => 'There is no value to encode.',
            'invalid_characters' => 'The value contains characters that the selected barcode type cannot encode.',
            'invalid_length' => 'The value has a length that the selected barcode type does not allow.',
            'invalid_checksum' => 'The check digit of the value is wrong.',
            'unsupported_type' => 'The barcode type is not supported.',
            'unsupported_format' => 'The output format is not supported; use svg or png.',
            'rendering_failed' => 'The barcode could not be rendered.',
        ],
    ],

    'problem_titles' => [
        'barcode' => 'Barcode cannot be generated',
        'labels' => 'Labels cannot be printed',
    ],

    'messages' => [
        'presets' => 'Label presets retrieved successfully',
    ],

];
