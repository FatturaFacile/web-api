<?php

declare(strict_types=1);

return [
    'password' => [
        'too_short' => 'The password must be at least :min characters long.',
        'mixed_case' => 'The password must contain both uppercase and lowercase letters.',
        'numbers' => 'The password must contain at least one number.',
        'symbols' => 'The password must contain at least one symbol.',
        'reused' => 'The password was used recently: choose one different from the last :count.',
    ],
    'messages' => [
        'policy_updated' => 'Security policy updated.',
        'sso_saved' => 'Single sign-on provider saved.',
        'sso_removed' => 'Single sign-on provider removed.',
        'scim_token_rotated' => 'SCIM token created. Copy it now: it will not be shown again.',
        'scim_token_revoked' => 'SCIM token revoked.',
    ],
    'errors' => [
        'account_locked_title' => 'Account locked',
        'account_locked_detail' => 'Too many wrong passwords. The account is locked for a while: try again later.',
        'password_expired_title' => 'Password expired',
        'password_expired_detail' => 'Your password is older than the maximum age set by your organization. Reset it with the "forgot password" link to sign in.',
        'sso_required_title' => 'Single sign-on required',
        'sso_required_detail' => 'Your organization requires single sign-on: sign in through your company identity provider.',
        'session_expired_title' => 'Session expired',
        'session_expired_detail' => 'The session was closed after a period of inactivity. Sign in again.',
        'session_tenant_mismatch_title' => 'Wrong organization',
        'session_tenant_mismatch_detail' => 'This session was opened through the single sign-on of another organization and cannot be used here. Sign in again.',
        'sso_not_ready_title' => 'Single sign-on is not ready',
        'sso_not_ready_detail' => 'SSO-only mode needs an enabled identity provider and your own account linked to it, otherwise you would lock yourself out. Sign in once through SSO first.',
        'sso_only_active_title' => 'SSO-only mode is on',
        'sso_only_active_detail' => 'Turn off SSO-only mode before disabling or removing the identity provider.',
        'sso_not_configured_title' => 'No identity provider',
        'sso_not_configured_detail' => 'This organization has no single sign-on provider configured.',
        'sso_unavailable_title' => 'Single sign-on unavailable',
        'sso_unavailable_detail' => 'Single sign-on is not available for this organization.',
        'sso_failed_title' => 'Sign-in failed',
        'sso_failed_detail' => 'The sign-in with the identity provider could not be completed.',
        'roles_required' => 'Choose at least one role.',
        'https_required' => 'The address must use https.',
        'endpoint_required' => 'This endpoint is required when discovery is not used.',
        'jit_needs_role' => 'Just-in-time provisioning needs a default role or a claim to role mapping.',
        'endpoint_unsafe' => 'The endpoint is not allowed (:reason).',
        'discovery_failed' => 'The discovery document could not be used (:reason).',
    ],
];
