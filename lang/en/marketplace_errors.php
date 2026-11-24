<?php

declare(strict_types=1);

return [
    'installation' => [
        'unknown_connector' => 'This connector is not in the catalog.',
        'limit' => 'A company can have at most :max installations of the same connector.',
        'name_taken' => 'An installation of this connector with this name already exists.',
        'scopes_missing' => 'The connector needs these scopes to be granted: :scopes.',
        'scope_not_allowed' => 'The scope ":scope" is not valid or you cannot grant it.',
        'not_configured' => 'Configure the installation first.',
        'unhealthy' => 'The health check failed, so the installation was not enabled: :message',
        'up_to_date' => 'The installation already runs the latest version of the connector.',
        'run_in_progress' => 'A synchronisation is in progress: wait for it to finish before uninstalling.',
        'rate_limit_above_max' => 'The rate limit cannot be above :max calls a minute, the maximum of this connector.',
    ],
    'config' => [
        'unknown_field' => 'This field is not part of the connector configuration.',
        'required' => 'The field ":field" is required.',
        'invalid_text' => 'The field ":field" must be a text of at most 255 characters.',
        'invalid_secret' => 'The field ":field" must be a secret of at least 4 characters.',
        'invalid_number' => 'The field ":field" must be a number.',
        'invalid_boolean' => 'The field ":field" must be true or false.',
        'invalid_option' => 'The field ":field" has a value that is not allowed.',
        'invalid_url' => 'The field ":field" must be a public https address.',
    ],
    'health' => [
        'unreachable' => 'The external system did not answer.',
        'address_refused' => 'The address of the external system is not allowed.',
        'egress_blocked' => 'The data residency policy of the company does not allow sending data to the country of the external system.',
        'failed' => 'The health check could not be completed.',
        'credentials_rejected' => 'The external system rejected the credentials.',
        'rate_limited' => 'The external system is limiting the requests.',
        'server_error' => 'The external system answered with an error (:status).',
        'unexpected_status' => 'The external system answered with an unexpected status (:status).',
    ],
    'sync' => [
        'not_enabled' => 'Enable the installation before synchronising it.',
        'unknown_resource' => 'This connector has no resource ":resource".',
        'rate_limited' => 'The rate limit of the installation is used up: retry in :seconds seconds.',
        'already_running' => 'This resource is already being synchronised.',
        'stale' => 'The run never finished and was closed by the next one.',
        'failed' => 'The synchronisation failed.',
        'status' => 'The external system answered with an error (:status).',
        'credential_missing' => 'The credential ":field" is missing: configure the installation again.',
        'fetched' => 'Fetched :count records of :resource.',
        'delivered' => 'The event was delivered.',
    ],
    'mapping' => [
        'missing_field' => 'The field ":field" is missing.',
        'invalid_amount' => 'The amount ":field" is not a number.',
        'invalid_currency' => 'The currency is not a valid ISO code.',
        'invalid_date' => 'The date is not valid.',
        'invalid_lines' => 'The order lines are not valid.',
    ],
];
