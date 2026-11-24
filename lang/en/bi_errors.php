<?php

declare(strict_types=1);

return [
    'formula' => [
        'length' => 'The formula must not be empty and can use at most :max characters.',
        'number' => 'The number ":token" is not valid: use digits with at most 8 decimals.',
        'character' => 'The character ":character" is not allowed in a formula: use numbers, measure keys, + - * / and parentheses.',
        'incomplete' => 'The formula is incomplete.',
        'parenthesis' => 'A parenthesis is not closed.',
        'unexpected' => 'Unexpected ":token" in the formula.',
        'too_deep' => 'The formula is too deeply nested.',
        'no_reference' => 'The formula must use at least one measure of the dataset.',
        'unknown_measure' => 'The formula uses ":measure", which is not a measure of the dataset.',
    ],
    'calculated' => [
        'key_taken_by_dataset' => 'The key ":key" is already a measure or a dimension of the dataset.',
        'key_exists' => 'A calculated measure with the key ":key" already exists on this dataset.',
        'limit' => 'A dataset can have at most :max calculated measures.',
        'in_use' => 'The measure is still used by: :usage. Remove it from them first.',
    ],
    'query' => [
        'unknown_calculated' => 'This calculated measure does not exist on the dataset.',
    ],
    'pivot' => [
        'measure_missing' => 'The pivot value must be a measure of the query.',
        'dimension_missing' => 'The pivot row or column must be a dimension of the query.',
        'dimension_repeated' => 'The dimension ":key" is used twice in the pivot.',
        'no_values' => 'Choose at least one measure for the cells of the pivot.',
        'no_axis' => 'Choose at least one dimension for the rows or the columns of the pivot.',
        'too_large' => 'The pivot would have more than :max cells: filter or group the data further.',
    ],
    'widget' => [
        'limit' => 'A dashboard can have at most :max widgets.',
        'source_required' => 'Choose a saved query or give a dataset and a specification.',
        'source_conflict' => 'Use either a saved query or a dataset with a specification, not both.',
        'saved_query_missing' => 'The saved query does not exist or you cannot open it.',
        'grid_bounds' => 'The widget must stay inside the :columns columns of the grid.',
        'overlap' => 'The widget overlaps another widget of the dashboard.',
        'not_in_dashboard' => 'This widget does not belong to the dashboard.',
        'drill_too_deep' => 'There is no further level to drill into.',
        'drill_mismatch' => 'The drill-down must follow the drill path: expected the dimension ":expected".',
        'drill_path_unknown' => 'The drill path must name dimensions of the dataset.',
        'drill_path_date' => 'The date dimension ":key" cannot be used in a drill path.',
        'drill_path_repeated' => 'The dimension ":key" is repeated in the drill path.',
    ],
    'share' => [
        'not_member' => 'The user is not a member of this company.',
        'owner' => 'The owner already has full access.',
    ],
    'snapshot' => [
        'owner_gone' => 'The owner of this query no longer exists.',
        'owner_left' => 'The owner of this query is no longer a member of the company.',
        'not_comparable' => 'Only a successful snapshot of a saved query can be compared with today.',
    ],
];
