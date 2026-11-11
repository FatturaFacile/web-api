<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * RFC 7807 `type` of the authentication problems the clients have to tell apart.
 */
enum AuthProblem: string
{
    case EmailNotVerified = 'urn:fatturafacile:problem:email-not-verified';
    case TenantApprovalPending = 'urn:fatturafacile:problem:tenant-approval-pending';
    case TenantAccessRejected = 'urn:fatturafacile:problem:tenant-access-rejected';
    case VerificationTokenInvalid = 'urn:fatturafacile:problem:verification-token-invalid';
    case VerificationTokenExpired = 'urn:fatturafacile:problem:verification-token-expired';
    case PasswordResetTokenInvalid = 'urn:fatturafacile:problem:password-reset-token-invalid';
    case PasswordResetTokenExpired = 'urn:fatturafacile:problem:password-reset-token-expired';
    case PasswordResetLocked = 'urn:fatturafacile:problem:password-reset-locked';
    case PasswordChangeLocked = 'urn:fatturafacile:problem:password-change-locked';
    case TwoFactorChallengeInvalid = 'urn:fatturafacile:problem:two-factor-challenge-invalid';
    case TwoFactorCodeInvalid = 'urn:fatturafacile:problem:two-factor-code-invalid';
    case TwoFactorSetupRequired = 'urn:fatturafacile:problem:two-factor-setup-required';
    case AccountLocked = 'urn:fatturafacile:problem:account-locked';
    case PasswordExpired = 'urn:fatturafacile:problem:password-expired';
    case SsoRequired = 'urn:fatturafacile:problem:sso-required';
    case SsoLoginFailed = 'urn:fatturafacile:problem:sso-login-failed';
    case SessionExpired = 'urn:fatturafacile:problem:session-expired';
    case SessionTenantMismatch = 'urn:fatturafacile:problem:session-tenant-mismatch';
    case CookieSessionRequired = 'urn:fatturafacile:problem:cookie-session-required';
}
