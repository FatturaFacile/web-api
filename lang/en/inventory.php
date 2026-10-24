<?php

declare(strict_types=1);

return [

    'messages' => [
        'product_updated' => 'Product updated successfully',
    ],

    'variant_inactive' => 'Variant :sku is deactivated: reactivate it before loading stock into it.',
    'parent_is_variant' => 'A variant cannot be the parent of other variants.',
    'duplicate_combination' => 'A variant with the same attributes already exists for this product.',
    'attributes_required' => 'At least one attribute is required.',
    'attributes_max' => 'A variant can combine at most :max attributes.',
    'attribute_value_invalid' => 'The value of attribute ":attribute" must be a non-empty text of at most 100 characters.',
    'attribute_name_invalid' => 'Attribute names must be non-empty texts of at most 50 characters.',
    'attribute_values_duplicated' => 'Attribute ":attribute" lists the same value more than once.',
    'too_many_combinations' => 'The matrix would generate :count variants; the maximum is :max.',
    'price_modifier_invalid' => 'The price modifier would make a negative price.',
    'variant_has_references' => 'The variant is referenced by stock movements or documents and cannot be deleted.',
    'barcode_generation_failed' => 'A unique barcode could not be generated.',
    'variant_name' => ':name (:attributes)',

];
