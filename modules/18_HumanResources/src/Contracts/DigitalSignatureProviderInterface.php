<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\DTOs\SignatureRequestResultDTO;
use Modules\HumanResources\Models\JobOffer;

/**
 * Electronic signature of offer letters. A real vendor integration can replace the manual default provider.
 */
interface DigitalSignatureProviderInterface
{
    public function name(): string;

    public function requestSignature(JobOffer $offer): SignatureRequestResultDTO;
}
