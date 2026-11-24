<?php

declare(strict_types=1);

return [
    'order' => [
        'approvers_required' => 'Add at least one approver before submitting.',
        'affected_required' => 'Add at least one affected item or bill of material before submitting.',
        'not_in_review' => 'The change order is not in review.',
        'cannot_cancel' => 'Only a draft, in review or approved change order can be cancelled.',
        'not_approved' => 'Only an approved change order can be implemented.',
        'not_draft' => 'Only a draft change order can be changed.',
        'code_taken' => 'The code :code is already used by another change order.',
    ],
    'approval' => [
        'not_your_turn' => 'It is not your turn to decide this change order.',
        'reject_comment_required' => 'A rejection needs a comment.',
    ],
    'revision' => [
        'exists' => 'The revision :revision already exists for this item.',
        'change_order_required' => 'The item already has a released revision: release the new one by implementing a change order.',
        'not_released' => 'Only a released revision can be made obsolete.',
        'not_draft' => 'Only a draft revision can be changed or released.',
    ],
];
