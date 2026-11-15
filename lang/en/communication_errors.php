<?php

declare(strict_types=1);

return [
    'channel' => [
        'not_configured' => 'Configure the channel before testing it.',
    ],
    'conversation' => [
        'closed' => 'The conversation is closed.',
    ],
    'campaign' => [
        'not_cancellable' => 'Only a draft or scheduled campaign can be cancelled.',
        'segment_not_found' => 'Select a segment that exists in this company.',
        'locked' => 'A sent or running campaign can no longer be changed.',
        'segment_required' => 'Choose the segment of the audience first.',
    ],
    'template' => [
        'not_found' => 'No active template for this code and channel.',
        'missing_variables' => 'Missing variables: :variables.',
    ],
];
