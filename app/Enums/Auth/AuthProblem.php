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
}
