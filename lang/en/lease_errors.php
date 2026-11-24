<?php

declare(strict_types=1);

return [
    'contract' => [
        'transition' => 'The lease is :status: it cannot become :target.',
        'only_draft_editable' => 'The lease is :status: only a draft can be changed. Use a remeasurement to change an active lease.',
        'only_draft_deletable' => 'The lease is :status: only a draft can be deleted.',
        'not_active' => 'The lease is :status: it must be active.',
        'exempt_not_measured' => 'A short-term or low-value lease with the exemption is expensed: it has no measurement and no schedule.',
        'negative_rou' => 'The right-of-use asset would not be positive: the incentives received exceed the lease liability.',
        'term_too_long' => 'The term cannot exceed :max months.',
        'term_multiple' => 'The term of :term months must be a whole number of payment periods of :frequency months.',
        'extension_required' => 'State the months of the extension when it is reasonably certain to be exercised.',
        'purchase_option_required' => 'A purchase option reasonably certain to be exercised needs its price and the useful life of the asset.',
        'useful_life_short' => 'The useful life of the asset cannot be shorter than the lease term.',
        'short_term_limit' => 'A short-term lease cannot last more than :max months nor include a purchase option that is reasonably certain to be exercised.',
        'low_value_required' => 'State the value of the asset when new to elect the low-value exemption.',
        'low_value_limit' => 'The asset is worth :value :currency when new: the low-value exemption allows up to :max :currency.',
    ],
    'remeasure' => [
        'effective_not_period_start' => 'The effective date must be the first day of a month of the schedule.',
        'period_already_posted' => 'Periods up to :period are already posted: the remeasurement must take effect from period :next on.',
        'rate_required' => 'A modification or a reassessment needs the revised discount rate.',
        'rate_not_allowed' => 'An index change keeps the original discount rate.',
        'payment_required' => 'An index change needs the new payment amount.',
        'term_fixed_for_index' => 'An index change does not change the remaining term.',
        'term_zero' => 'The remaining term must be at least one month.',
        'term_multiple' => 'The lease must end after a whole number of payment periods of :frequency months.',
    ],
    'posting' => [
        'not_postable' => 'The lease is :status: only an active lease with a schedule can be posted.',
    ],
];
