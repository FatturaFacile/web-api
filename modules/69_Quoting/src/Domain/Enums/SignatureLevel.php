<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Enums;

enum SignatureLevel: string
{
    case SesOtp = 'ses_otp';
    case Aes = 'aes';
    case Qes = 'qes';

    public function requiresTrustServiceProvider(): bool
    {
        return $this !== self::SesOtp;
    }
}
