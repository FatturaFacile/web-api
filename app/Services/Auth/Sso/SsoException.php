<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use RuntimeException;

/**
 * An SSO step that failed. The reason is a stable code for the audit trail; the client only ever gets a generic answer,
 * so a failed login does not tell an attacker which check stopped them.
 */
final class SsoException extends RuntimeException
{
    public ?string $tenantId = null;

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail === '' ? $reason : $reason.': '.$detail);
    }

    /**
     * Names the tenant whose login failed, for the audit trail.
     */
    public function forTenant(?string $tenantId): self
    {
        $this->tenantId = $tenantId;

        return $this;
    }
}
