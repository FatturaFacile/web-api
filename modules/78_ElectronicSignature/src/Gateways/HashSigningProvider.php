<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\ElectronicSignature\Contracts\ElectronicSignatureProvider;
use Modules\ElectronicSignature\DTOs\ChallengeResult;
use Modules\ElectronicSignature\DTOs\PreparedPdf;
use Modules\ElectronicSignature\DTOs\SigningInput;
use Modules\ElectronicSignature\DTOs\SigningResult;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;
use Modules\ElectronicSignature\Models\SignatureRecipient;
use Modules\ElectronicSignature\Services\PdfSignatureService;
use Modules\ElectronicSignature\Services\PlatformSealService;

/**
 * A trust service provider that signs a hash: the platform prepares the PDF with an empty signature field and sends the
 * provider only the SHA-256 digest of the ByteRange; the provider returns the CMS (PKCS#7) signature, with the time stamp
 * (RFC 3161) and the revocation data (CRL / OCSP) it embeds for long-term validation, and the platform places it in the
 * field. The signatures of an envelope are applied one after the other on a single working copy.
 *
 * In sandbox mode nothing leaves the server: the digest is signed with the seal key of the company, so the whole flow runs
 * locally and in CI. A sandbox signature is not a qualified signature and is labelled as such in its certificate.
 */
abstract class HashSigningProvider implements ElectronicSignatureProvider
{
    final public function __construct(
        protected readonly PdfSignatureService $pdf,
        protected readonly PlatformSealService $seal,
    ) {}

    final public function requiresSequentialRouting(): bool
    {
        return true;
    }

    final public function openEnvelope(SignatureEnvelope $envelope, SignatureProviderConfig $config, string $pdf): ?string
    {
        return null;
    }

    final public function sign(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, SigningInput $input): SigningResult
    {
        if ($envelope->document_format !== 'pades') {
            return $this->signStructured($envelope, $recipient, $config, $input);
        }

        if ($config->is_sandbox) {
            $this->assertSandboxChallenge($recipient, $input);
        }

        $prepared = $this->pdf->prepare($input->pdf, $input->appearance);
        $remote = $config->is_sandbox
            ? $this->sandboxSignature($envelope, $config, $prepared)
            : $this->requestSignature($envelope, $recipient, $config, $prepared, $input);

        return $this->resultOf($remote, $prepared, $envelope);
    }

    final public function poll(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): SigningResult
    {
        if ($recipient->prepared_path === null) {
            throw new SignatureException('nothing_pending');
        }

        $bytes = Storage::disk((string) config('electronic_signature.disk', 'local'))->get($recipient->prepared_path);

        if ($bytes === null) {
            throw new SignatureException('nothing_pending');
        }

        $prepared = $this->pdf->preparedFromBytes($bytes);

        return $this->resultOf($this->pollSignature($envelope, $recipient, $config, $prepared), $prepared, $envelope);
    }

    final public function finalize(SignatureEnvelope $envelope, SignatureProviderConfig $config, string $workingPdf, array $stamps): string
    {
        // Every signature is already in the working copy; nothing is drawn on top of it, that would break them.
        return $workingPdf;
    }

    public function cancel(SignatureEnvelope $envelope, SignatureProviderConfig $config): void {}

    public function startChallenge(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): ChallengeResult
    {
        return new ChallengeResult(ChallengeResult::Push, channel: $recipient->auth_method->value);
    }

    /**
     * Asks the provider to sign the digest.
     *
     * @return array{status: string, cms?: string|null, serial?: string|null, subject?: string|null, external_id?: string|null, receipt?: string|null, evidence?: array<string, mixed>}
     *
     * @throws SignatureException
     */
    abstract protected function requestSignature(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, PreparedPdf $prepared, SigningInput $input): array;

    /**
     * @return array{status: string, cms?: string|null, serial?: string|null, subject?: string|null, external_id?: string|null, receipt?: string|null, evidence?: array<string, mixed>}
     */
    abstract protected function pollSignature(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, PreparedPdf $prepared): array;

    /**
     * The identity check a sandbox simulates; the providers with a typed code override it.
     */
    protected function assertSandboxChallenge(SignatureRecipient $recipient, SigningInput $input): void {}

    /**
     * A document that is not a PDF (CAdES, XAdES) is signed by the provider as a whole; the providers that support it
     * override this. The default refuses.
     */
    protected function signStructured(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, SigningInput $input): SigningResult
    {
        throw new SignatureException('format_not_supported');
    }

    /**
     * @param  array{status: string, cms?: string|null, serial?: string|null, subject?: string|null, external_id?: string|null, receipt?: string|null, evidence?: array<string, mixed>}  $remote
     */
    private function resultOf(array $remote, PreparedPdf $prepared, SignatureEnvelope $envelope): SigningResult
    {
        if ($remote['status'] === 'pending') {
            return new SigningResult(
                SigningResult::Pending,
                $this->type()->value,
                externalId: $remote['external_id'] ?? null,
                evidence: $remote['evidence'] ?? [],
                preparedPdf: $prepared->bytes,
            );
        }

        if ($remote['status'] !== 'signed' || ($remote['cms'] ?? '') === '') {
            throw new SignatureException('provider_rejected', 422, ['detail' => (string) ($remote['evidence']['detail'] ?? '')]);
        }

        return new SigningResult(
            status: SigningResult::Signed,
            method: $this->type()->value.'_'.strtolower($envelope->required_level->value),
            pdf: $this->pdf->embed($prepared, (string) $remote['cms']),
            certificateSerial: $remote['serial'] ?? null,
            certificateSubject: $remote['subject'] ?? null,
            externalId: $remote['external_id'] ?? null,
            receipt: $remote['receipt'] ?? null,
            evidence: $remote['evidence'] ?? [],
        );
    }

    /**
     * @return array{status: string, cms: string, serial: string, subject: string, external_id: string, receipt: string, evidence: array<string, mixed>}
     */
    private function sandboxSignature(SignatureEnvelope $envelope, SignatureProviderConfig $config, PreparedPdf $prepared): array
    {
        $material = $this->seal->material($config);
        $transaction = $this->type()->value.'-sandbox-'.substr($prepared->digestHex(), 0, 16);
        $parsed = openssl_x509_parse($material['certificate']);

        return [
            'status' => 'signed',
            'cms' => $this->pdf->cmsForPrepared($prepared, $material['certificate'], $material['key'], $material['chain']),
            'serial' => is_array($parsed) ? (string) ($parsed['serialNumberHex'] ?? '') : '',
            'subject' => is_array($parsed) ? (string) ($parsed['name'] ?? '') : '',
            'external_id' => $transaction,
            'receipt' => $transaction,
            'evidence' => ['sandbox' => true, 'level' => $envelope->required_level->value, 'digest' => $prepared->digestHex()],
        ];
    }

    /**
     * A JSON request with retries on connection errors and server errors; 4xx is the provider saying no and is not retried.
     *
     * @param  array<string, mixed>  $options  Guzzle options (mTLS certificate and key)
     */
    protected function http(SignatureProviderConfig $config, array $options = []): PendingRequest
    {
        $timeout = (int) config('electronic_signature.providers.'.$this->type()->value.'.timeout', 30);

        return Http::acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withOptions($options)
            ->retry(3, 300, static fn (\Throwable $e): bool => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    /**
     * @throws SignatureException 502 when the provider is unreachable or fails, 422 when it refuses the request
     */
    protected function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new SignatureException(
            $response->serverError() ? 'provider_unavailable' : 'provider_rejected',
            $response->serverError() ? 502 : 422,
            ['status' => $response->status(), 'detail' => mb_substr($response->body(), 0, 300)],
        );
    }

    protected function baseUrl(SignatureProviderConfig $config): string
    {
        return rtrim((string) ($config->setting('base_url') ?: config('electronic_signature.providers.'.$this->type()->value.'.base_url')), '/');
    }
}
