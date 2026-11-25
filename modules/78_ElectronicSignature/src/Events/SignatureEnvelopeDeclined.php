<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\ElectronicSignature\Models\SignatureEnvelope;

/**
 * A signature envelope reached the final status "declined". The listeners update the business document that
 * was sent for signature and archive the signed file with its audit dossier.
 */
final class SignatureEnvelopeDeclined
{
    use Dispatchable;

    public function __construct(public readonly SignatureEnvelope $envelope) {}
}
