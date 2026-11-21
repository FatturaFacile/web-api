<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Identity & access management (Mod 004)
|--------------------------------------------------------------------------
|
| mandatory_mfa: owners and administrators must use a second factor (TOTP or a passkey) and cannot turn it off. The tenant
| policy of Mod 114 (TenantSecurityPolicy) decides for every other role. Turn it off only on local or test environments.
|
| impersonation_max_minutes: hard ceiling of a support session started with the consent of the tenant owner.
|
*/

return [
    'mandatory_mfa' => filter_var(env('IAM_MANDATORY_MFA', true), FILTER_VALIDATE_BOOL),
    'impersonation_max_minutes' => (int) env('IAM_IMPERSONATION_MAX_MINUTES', 30),
    'inactive_user_days' => (int) env('IAM_INACTIVE_USER_DAYS', 90),
];
