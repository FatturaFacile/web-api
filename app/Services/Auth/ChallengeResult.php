<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * The outcome of one answer to a second-factor challenge: the method that passed, or the method that was tried and why
 * it failed.
 */
final readonly class ChallengeResult
{
    private function __construct(
        public ?string $method,
        public ?string $attemptedMethod,
        public ?string $failureReason,
    ) {}

    public static function passed(string $method): self
    {
        return new self($method, $method, null);
    }

    public static function failed(?string $attemptedMethod, string $reason): self
    {
        return new self(null, $attemptedMethod, $reason);
    }

    public function isPassed(): bool
    {
        return $this->method !== null;
    }
}
