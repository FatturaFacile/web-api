<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Events\SignatureEnvelopeCompleted;
use Modules\ElectronicSignature\Events\SignatureEnvelopeDeclined;
use Modules\ElectronicSignature\Events\SignatureEnvelopeExpired;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Services\SignableDocumentRegistry;
use Throwable;

/**
 * Tells the business document that was sent for signature how the envelope ended: a quote is accepted or rejected, a
 * purchase order approved or rejected, an employment contract records the outcome. A failure here never undoes a signature.
 */
final class ApplySignatureOutcome
{
    public function __construct(private readonly SignableDocumentRegistry $documents) {}

    public function handleCompleted(SignatureEnvelopeCompleted $event): void
    {
        $this->apply($event->envelope, 'onCompleted');
    }

    public function handleDeclined(SignatureEnvelopeDeclined $event): void
    {
        $this->apply($event->envelope, 'onDeclined');
    }

    public function handleExpired(SignatureEnvelopeExpired $event): void
    {
        $this->apply($event->envelope, 'onExpired');
    }

    /**
     * @param  'onCompleted'|'onDeclined'|'onExpired'  $method
     */
    private function apply(SignatureEnvelope $envelope, string $method): void
    {
        $type = $envelope->signableKind();

        if ($type === null || $type === SignableType::Upload || $envelope->signable_id === null) {
            return;
        }

        try {
            $source = $this->documents->source($type);
            $record = $source->find($envelope->signable_id);

            if ($record !== null) {
                $source->{$method}($record, $envelope->refresh());
            }
        } catch (Throwable $exception) {
            Log::error('electronic-signature.outcome.failed', ['envelope' => $envelope->id, 'event' => $method, 'error' => $exception->getMessage()]);
        }
    }
}
