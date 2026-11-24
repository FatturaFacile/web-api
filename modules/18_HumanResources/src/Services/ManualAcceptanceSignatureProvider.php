<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Illuminate\Support\Str;
use Modules\HumanResources\Contracts\DigitalSignatureProviderInterface;
use Modules\HumanResources\DTOs\SignatureRequestResultDTO;
use Modules\HumanResources\Enums\SignatureStatus;
use Modules\HumanResources\Models\JobOffer;

/**
 * Default signature provider: no external vendor, the acceptance is recorded by HR through
 * `POST /offers/{id}/mark-signed` (the same action a real provider callback would call).
 */
final readonly class ManualAcceptanceSignatureProvider implements DigitalSignatureProviderInterface
{
    public function name(): string
    {
        return 'manual';
    }

    public function requestSignature(JobOffer $offer): SignatureRequestResultDTO
    {
        return new SignatureRequestResultDTO(
            status: SignatureStatus::Pending,
            externalId: 'MAN-'.strtoupper(Str::random(12))
        );
    }
}
