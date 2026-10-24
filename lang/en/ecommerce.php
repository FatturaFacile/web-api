<?php

declare(strict_types=1);

return [

    'errors' => [
        'unsupported_driver' => 'The e-commerce driver ":driver" is not supported.',
        'missing_configuration' => 'The channel is missing a required setting: :field.',
        'http_error' => 'The shop answered :status to :operation.',
        'invalid_response' => 'The shop returned a response that could not be understood (:operation).',
        'invalid_url' => 'The URL must be a public http or https address.',
        'credentials_required' => 'The credential ":field" is required for the :driver driver.',
        'channel_inactive' => 'This channel is not active.',
        'sync_failed' => 'The synchronisation with the shop failed.',
        'unknown_channel' => 'The channel does not exist or is not active.',
        'invalid_signature' => 'The webhook signature is invalid.',
    ],

    'order' => [
        'note' => 'E-commerce order :number imported from :channel',
        'shipping' => 'Shipping: :method',
        'shipping_fallback' => 'Shipping',
        'line_fallback' => 'Item :id',
    ],

    'problem_titles' => [
        'sync' => 'Shop synchronisation failed',
        'webhook' => 'Webhook rejected',
    ],

    'messages' => [
        'channels' => 'E-commerce channels retrieved successfully',
        'channel' => 'E-commerce channel retrieved successfully',
        'channel_created' => 'E-commerce channel created successfully',
        'channel_updated' => 'E-commerce channel updated successfully',
        'channel_deleted' => 'E-commerce channel deleted successfully',
        'connection_ok' => 'The connection to the shop works.',
        'connection_failed' => 'The shop did not accept the connection. Check the URL and the credentials.',
        'sync_done' => 'Synchronisation completed',
        'webhook_processed' => 'Webhook processed',
    ],

];
