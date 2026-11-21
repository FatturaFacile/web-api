<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Modules\Quoting\Application\Commands\InitiateQuoteSignatureCommand;
use Modules\Quoting\Application\Services\SignatureAdapterResolver;
use Modules\Quoting\Domain\Exceptions\QuoteException;
use Modules\Quoting\Infrastructure\Models\CpqSignatureEnvelope;
use Modules\Quoting\Infrastructure\Services\PublicQuoteGate;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;

final class InitiateQuoteSignatureHandler
{
    public function __construct(
        private readonly PublicQuoteGate $gate,
        private readonly SignatureAdapterResolver $adapters,
        private readonly QuoteAuditTrail $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(InitiateQuoteSignatureCommand $command): array
    {
        $quote = $this->gate->resolve($command->token, $command->session);

        if (! $command->consent) {
            throw new QuoteException('The explicit consent to sign electronically is required.');
        }

        $adapter = $this->adapters->for($quote->signature_level);
        $envelope = CpqSignatureEnvelope::query()->create([
            'tenant_id' => $quote->tenant_id,
            'quote_id' => $quote->id,
            'level' => $quote->signature_level->value,
            'provider' => $adapter->provider(),
            'status' => 'pending',
            'signer_name' => $command->signerName,
            'signer_role' => $command->signerRole,
            'signer_email' => $command->signerEmail ?? $quote->customer_email,
            'signer_phone' => $command->signerPhone ?? $quote->customer_phone,
            'otp_channel' => $command->channel,
            'consent_given' => true,
            'signer_ip' => $command->ip,
            'signer_user_agent' => $command->userAgent === null ? null : mb_substr($command->userAgent, 0, 500),
        ]);

        $step = $adapter->initiate($quote, $envelope);
        $this->audit->record($quote, 'signature_initiated', $command->signerName, $command->ip, ['envelope_id' => $envelope->id, 'level' => $quote->signature_level->value, 'provider' => $adapter->provider()]);

        if (isset($step['otp_sent'])) {
            $this->audit->record($quote, 'otp_sent', $command->signerName, $command->ip, ['channel' => $step['channel'] ?? null]);
        }

        return ['envelope_id' => $envelope->id] + $step;
    }
}
