<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Contracts;

use Modules\ElectronicSignature\DTOs\ChallengeResult;
use Modules\ElectronicSignature\DTOs\ConnectionTestResult;
use Modules\ElectronicSignature\DTOs\SigningInput;
use Modules\ElectronicSignature\DTOs\SigningResult;
use Modules\ElectronicSignature\DTOs\WebhookEvent;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;
use Modules\ElectronicSignature\Models\SignatureRecipient;

/**
 * A signature engine: the built-in one or a qualified trust service provider (QTSP). Every provider has a sandbox mode
 * (`SignatureProviderConfig::is_sandbox`) that simulates the service without network access, for local runs and CI.
 */
interface ElectronicSignatureProvider
{
    public function type(): ProviderType;

    /**
     * Whether the provider can issue a signature of the tier under the jurisdiction.
     */
    public function supports(SignatureLevel $level, Jurisdiction $jurisdiction): bool;

    /**
     * Whether signatures are applied one after the other on a single PDF (hash signing), so the envelope cannot be parallel.
     */
    public function requiresSequentialRouting(): bool;

    public function testConnection(SignatureProviderConfig $config): ConnectionTestResult;

    /**
     * Creates the envelope at the provider when the provider keeps one (the id comes back), nothing otherwise.
     *
     * @throws SignatureException
     */
    public function openEnvelope(SignatureEnvelope $envelope, SignatureProviderConfig $config, string $pdf): ?string;

    /**
     * Starts the identity check of a recipient: sends a code, pushes a request to the phone or builds the provider URL.
     *
     * @throws SignatureException
     */
    public function startChallenge(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): ChallengeResult;

    /**
     * Signs for a recipient.
     *
     * @throws SignatureException when the identity check fails or the provider refuses
     */
    public function sign(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, SigningInput $input): SigningResult;

    /**
     * Asks the provider for the result of a signature that was pending.
     */
    public function poll(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): SigningResult;

    /**
     * Produces the final signed document once every recipient has signed.
     *
     * @param  list<array{box: array{page: int, x: float, y: float, width: float, height: float}, lines: list<string>, qr?: string|null}>  $stamps  visual stamps of the signers that the platform draws
     *
     * @throws SignatureException
     */
    public function finalize(SignatureEnvelope $envelope, SignatureProviderConfig $config, string $workingPdf, array $stamps): string;

    public function cancel(SignatureEnvelope $envelope, SignatureProviderConfig $config): void;

    /**
     * The envelope id a callback payload refers to, read before the authenticity check to find the right configuration.
     */
    public function envelopeIdFromWebhook(string $payload): ?string;

    /**
     * Checks the authenticity of a callback (HMAC over the timestamped body, replay window) and normalises it.
     *
     * @param  array<string, string>  $headers  lower-case header names
     *
     * @throws SignatureException when the callback is not authentic or is stale
     */
    public function parseWebhook(string $payload, array $headers, SignatureProviderConfig $config): WebhookEvent;
}
