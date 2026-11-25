<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

/**
 * Checks the e-mail domain against the convention, creates a time-limited OTP and e-mails it.
 */
final readonly class RequestWorkEmailOtpCommand
{
    public function __construct(
        public string $conventionId,
        public string $email,
        public string $locale,
    ) {}
}
