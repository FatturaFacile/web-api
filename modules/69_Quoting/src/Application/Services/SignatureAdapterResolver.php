<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Services;

use Modules\Quoting\Domain\Contracts\ElectronicSignaturePort;
use Modules\Quoting\Domain\Enums\SignatureLevel;

final class SignatureAdapterResolver
{
    public function __construct(
        private readonly ElectronicSignaturePort $otp,
        private readonly ElectronicSignaturePort $tsp,
    ) {}

    public function for(SignatureLevel $level): ElectronicSignaturePort
    {
        return $level->requiresTrustServiceProvider() ? $this->tsp : $this->otp;
    }
}
