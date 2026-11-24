<?php

declare(strict_types=1);

return [
    /*
    | Address of the family portal front end; the personal token is appended as ?token=.
    */
    'family_portal_url' => env('CHILDCARE_FAMILY_PORTAL_URL', env('APP_URL').'/childcare-family'),

    /*
    | Days a personal link of the family portal stays valid.
    */
    'family_token_days' => (int) env('CHILDCARE_FAMILY_TOKEN_DAYS', 90),

    /*
    | Private disk of the documents in the virtual folders of the children.
    */
    'documents_disk' => env('CHILDCARE_DOCUMENTS_DISK', 'private'),
];
