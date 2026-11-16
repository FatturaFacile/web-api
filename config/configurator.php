<?php

declare(strict_types=1);

return [

    /*
    | Minimum margin, in percent of the net revenue, below which a configuration needs a discount approval. A
    | configurable model can set its own limit with its min_margin_percent column.
    */
    'default_min_margin_percent' => env('CONFIGURATOR_DEFAULT_MIN_MARGIN_PERCENT', '15.00'),

    /*
    | Upper bound of the passes the default rules make over a selection: a default can enable an option whose own
    | default rule enables another one, and the chain is cut here to keep a bad rule set from looping.
    */
    'max_default_passes' => 10,

];
