<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Signature;

use Illuminate\Support\Str;
use Modules\Quoting\Domain\Contracts\ElectronicSignaturePort;
use Modules\Quoting\Domain\Exceptions\QuoteAccessDeniedException;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Models\CpqSignatureEnvelope;

/**
 * Level 2, advanced (AES) and qualified (QES) signature through a trust service provider (Swisscom Trust Services,
 * Skribble, InfoCert, Namirial...). Reference implementation: the ceremony is a redirect to the provider and the result
 * comes back as a webhook whose body is authenticated with HMAC-SHA256 over the raw payload using the shared secret.
 * A provider-specific client plugs in by overriding ceremonyUrl() (create the signature request through its API there).
 */
class QualifiedTspSignatureAdapter implements ElectronicSignaturePort
{
    public function provider(): string
    {
        return (string) config('quoting.tsp.provider', 'tsp');
    }

    public function initiate(CpqQuote $quote, CpqSignatureEnvelope $envelope): array
    {
        $reference = 'tsp_'.Str::lower(Str::random(32));
        $envelope->forceFill(['provider' => $this->provider(), 'provider_reference' => $reference])->save();

        return ['redirect_url' => $this->ceremonyUrl($quote, $envelope, $reference), 'provider_reference' => $reference];
    }

    /**
     * Proof: ['payload' => raw webhook body, 'signature' => hex HMAC header]. Valid only when the signature matches, the
     * provider reference is the one of this envelope and the provider reports the signature as completed.
     */
    public function verify(CpqSignatureEnvelope $envelope, array $proof): array
    {
        $secret = (string) config('quoting.tsp.webhook_secret', '');
        $payload = (string) ($proof['payload'] ?? '');
        $signature = strtolower((string) ($proof['signature'] ?? ''));

        if ($secret === '' || $payload === '' || ! hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            throw new QuoteAccessDeniedException('The signature provider callback is not authentic.');
        }

        $data = json_decode($payload, true);

        if (! is_array($data) || ($data['reference'] ?? null) !== $envelope->provider_reference || ($data['status'] ?? null) !== 'completed') {
            throw new QuoteAccessDeniedException('The signature provider callback does not complete this envelope.');
        }

        return [
            'method' => 'tsp',
            'provider' => $this->provider(),
            'provider_reference' => $envelope->provider_reference,
            'signature_level' => (string) ($data['level'] ?? $envelope->level->value),
            'certificate_serial' => $data['certificate_serial'] ?? null,
            'signed_at' => $data['signed_at'] ?? null,
        ];
    }

    protected function ceremonyUrl(CpqQuote $quote, CpqSignatureEnvelope $envelope, string $reference): string
    {
        $base = (string) config('quoting.tsp.ceremony_url', '');

        return $base === '' ? '' : $base.(str_contains($base, '?') ? '&' : '?').http_build_query(['reference' => $reference, 'document' => $quote->quote_number]);
    }
}
