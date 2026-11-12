<?php

declare(strict_types=1);

return [
    'report' => [
        'unknown_dataset' => 'Unknown dataset.',
        'role_cannot_read' => 'Your role cannot read the :module data this dataset is drawn from.',
        'no_active_company' => 'No active company.',
        'timeout' => 'The report took longer than :seconds seconds and was cancelled: narrow the period, add filters or group by fewer dimensions.',
    ],
    'spec' => [
        'dimensions_list' => 'Dimensions must be a list of at most :max items.',
        'dimension_repeated' => 'The dimension [:key] is repeated.',
        'measures_count' => 'Choose between 1 and :max measures.',
        'unknown_measure' => 'Unknown measure for this dataset.',
        'measure_repeated' => 'The measure [:measure] is repeated.',
        'unknown_comparison' => 'Unknown comparison.',
        'comparison_needs_period' => 'A comparison needs a dataset with a period and a period.',
        'dimension_format' => 'A dimension is a key, optionally followed by :granularity.',
        'unknown_dimension' => 'Unknown dimension for this dataset.',
        'unknown_granularity' => 'Unknown granularity for this dimension.',
        'filters_list' => 'Filters must be a list of at most :max items.',
        'unknown_dimension_or_measure' => 'Unknown dimension or measure for this dataset.',
        'operator_not_allowed' => 'Operator not allowed for this field.',
        'invalid_filter_value' => 'Invalid value for a :type dimension and the operator :operator.',
        'sort_list' => 'Sort must be a list of at most :max items.',
        'sort_field' => 'Sort only by a chosen dimension or measure.',
        'sort_direction' => 'Direction is asc or desc.',
        'sort_field_repeated' => 'The field is already used to sort.',
        'limit_range' => 'The top-N limit is a whole number between 1 and :max.',
        'no_period' => 'This dataset describes today and has no period.',
        'period_format' => 'The period is an object with a preset, or with from and to.',
        'unknown_preset' => 'Unknown preset.',
        'period_dates' => 'from and to are dates (Y-m-d).',
        'period_inverted' => 'The start of the period is after its end.',
        'period_too_long' => 'The period cannot be longer than :days days.',
    ],
];
