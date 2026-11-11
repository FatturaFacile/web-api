<?php

declare(strict_types=1);

namespace App\Services\Auth\Security;

/**
 * The security rules that apply to a tenant, or to a user who belongs to several: the platform defaults tightened by
 * the tenant policy.
 */
final readonly class EffectivePolicy
{
    /**
     * @param  list<string>  $twoFactorRoles
     */
    public function __construct(
        public int $passwordMinLength,
        public bool $passwordRequireMixedCase,
        public bool $passwordRequireNumbers,
        public bool $passwordRequireSymbols,
        public int $passwordHistoryCount,
        public ?int $passwordMaxAgeDays,
        public int $lockoutMaxAttempts,
        public int $lockoutMinutes,
        public ?int $sessionIdleTimeoutMinutes,
        public ?int $maxConcurrentSessions,
        public array $twoFactorRoles,
        public bool $ssoOnly,
    ) {}

    /**
     * The stricter of two policies, field by field: used for a user who belongs to several tenants, where the password
     * is one and the strictest tenant wins.
     */
    public function strictest(self $other): self
    {
        $smallest = static fn (?int $a, ?int $b): ?int => $a === null ? $b : ($b === null ? $a : min($a, $b));

        return new self(
            max($this->passwordMinLength, $other->passwordMinLength),
            $this->passwordRequireMixedCase || $other->passwordRequireMixedCase,
            $this->passwordRequireNumbers || $other->passwordRequireNumbers,
            $this->passwordRequireSymbols || $other->passwordRequireSymbols,
            max($this->passwordHistoryCount, $other->passwordHistoryCount),
            $smallest($this->passwordMaxAgeDays, $other->passwordMaxAgeDays),
            $this->lockoutMaxAttempts === 0 ? $other->lockoutMaxAttempts : ($other->lockoutMaxAttempts === 0 ? $this->lockoutMaxAttempts : min($this->lockoutMaxAttempts, $other->lockoutMaxAttempts)),
            max($this->lockoutMinutes, $other->lockoutMinutes),
            $smallest($this->sessionIdleTimeoutMinutes, $other->sessionIdleTimeoutMinutes),
            $smallest($this->maxConcurrentSessions, $other->maxConcurrentSessions),
            array_values(array_unique([...$this->twoFactorRoles, ...$other->twoFactorRoles])),
            $this->ssoOnly && $other->ssoOnly,
        );
    }
}
