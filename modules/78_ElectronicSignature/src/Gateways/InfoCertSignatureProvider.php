<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Modules\ElectronicSignature\DTOs\ChallengeResult;
use Modules\ElectronicSignature\DTOs\ConnectionTestResult;
use Modules\ElectronicSignature\DTOs\PreparedPdf;
use Modules\ElectronicSignature\DTOs\SigningInput;
use Modules\ElectronicSignature\DTOs\SigningResult;
use Modules\ElectronicSignature\DTOs\WebhookEvent;
use Modules\ElectronicSignature\Enums\AuthMethod;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;
use Modules\ElectronicSignature\Models\SignatureRecipient;

/**
 * Tinexta InfoCert (GoSign, remote signature): advanced and qualified signatures under eIDAS.
 *
 * The client authenticates with OAuth 2.0 client credentials; the identity of the signer is proven with an SMS one-time
 * code, a push to the InfoCert ID / GoSign Mobile app, or through SPID / CIE onboarding that InfoCert runs. The platform
 * sends the SHA-256 digest of the PDF ByteRange (PAdES); for CAdES (.p7m) and XAdES (XML) documents the provider signs the
 * whole file and returns the signed container.
 *
 * The paths of the endpoints (`settings.endpoints.token|challenge|sign|poll`) follow the InfoCert remote-signature
 * reference and are configurable per company, because InfoCert assigns them at onboarding. Credentials (encrypted):
 * `client_id`, `client_secret`, `signer_alias` (the remote signing account of the company), `webhook_secret`.
 */
final class InfoCertSignatureProvider extends HashSigningProvider
{
    private const array DefaultEndpoints = [
        'token' => '/oauth2/token',
        'challenge' => '/remote-signature/challenge',
        'sign' => '/remote-signature/sign',
        'poll' => '/remote-signature/status',
    ];

    public function type(): ProviderType
    {
        return ProviderType::InfoCert;
    }

    public function supports(SignatureLevel $level, Jurisdiction $jurisdiction): bool
    {
        return $jurisdiction === Jurisdiction::Eidas && $level !== SignatureLevel::Fes;
    }

    public function testConnection(SignatureProviderConfig $config): ConnectionTestResult
    {
        if ($config->is_sandbox) {
            return new ConnectionTestResult(true, 'sandbox_ok');
        }

        if ($config->credential('client_id') === null || $config->credential('client_secret') === null) {
            return new ConnectionTestResult(false, 'credentials_missing');
        }

        try {
            $this->accessToken($config, fresh: true);
        } catch (SignatureException $exception) {
            return new ConnectionTestResult(false, $exception->errorCode() === 'provider_rejected' ? 'credentials_rejected' : 'unreachable');
        }

        return new ConnectionTestResult(true, 'authenticated');
    }

    public function startChallenge(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): ChallengeResult
    {
        $channel = match ($recipient->auth_method) {
            AuthMethod::SmsOtp => 'sms',
            AuthMethod::MobileApp => 'push',
            AuthMethod::SpidCie => 'spid',
            default => throw new SignatureException('auth_method_not_supported'),
        };

        if ($channel !== 'spid' && ($recipient->phone === null || $recipient->phone === '')) {
            throw new SignatureException('phone_required');
        }

        if ($config->is_sandbox) {
            $code = $channel === 'sms' && ! app()->isProduction() ? '000000' : null;

            return new ChallengeResult($channel === 'sms' ? ChallengeResult::Otp : ChallengeResult::Push, channel: $channel, sandboxCode: $code);
        }

        try {
            $response = $this->http($config)->withToken($this->accessToken($config))->post($this->url($config, 'challenge'), [
                'signer' => $config->credential('signer_alias'),
                'channel' => $channel,
                'msisdn' => $recipient->phone,
                'email' => $recipient->email,
                'name' => $recipient->full_name,
                'language' => strtolower(substr($envelope->locale, 0, 2)),
                'reference' => $envelope->verification_code,
            ]);
        } catch (ConnectionException) {
            throw new SignatureException('provider_unavailable', 502);
        }

        $this->ensureSuccessful($response);
        $recipient->forceFill(['external_id' => (string) ($response->json('transactionId') ?? $response->json('id'))])->save();

        $url = $response->json('redirectUrl');

        return new ChallengeResult(
            is_string($url) && $url !== '' ? ChallengeResult::Redirect : ($channel === 'sms' ? ChallengeResult::Otp : ChallengeResult::Push),
            reference: $recipient->external_id,
            url: is_string($url) ? $url : null,
            channel: $channel,
        );
    }

    protected function requestSignature(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, PreparedPdf $prepared, SigningInput $input): array
    {
        return $this->callSign($config, [
            'signer' => $config->credential('signer_alias'),
            'transactionId' => $recipient->external_id,
            'otp' => $input->otp,
            'level' => $envelope->required_level->value === 'FEQ' ? 'QES' : 'AES',
            'format' => 'PADES',
            'hashes' => [['algorithm' => 'SHA-256', 'value' => $prepared->digestBase64()]],
            'timestamp' => true,
            'ltv' => true,
        ]);
    }

    protected function pollSignature(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, PreparedPdf $prepared): array
    {
        try {
            $response = $this->http($config)->withToken($this->accessToken($config))->get($this->url($config, 'poll').'/'.rawurlencode((string) $recipient->external_id));
        } catch (ConnectionException) {
            throw new SignatureException('provider_unavailable', 502);
        }

        $this->ensureSuccessful($response);

        return $this->normalise($response->json() ?? []);
    }

    protected function signStructured(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, SigningInput $input): SigningResult
    {
        if ($config->is_sandbox) {
            throw new SignatureException('format_not_supported');
        }

        $remote = $this->callSign($config, [
            'signer' => $config->credential('signer_alias'),
            'transactionId' => $recipient->external_id,
            'otp' => $input->otp,
            'level' => $envelope->required_level->value === 'FEQ' ? 'QES' : 'AES',
            'format' => strtoupper($envelope->document_format),
            'document' => base64_encode($input->pdf),
            'fileName' => $envelope->file_name,
        ]);

        if ($remote['status'] !== 'signed') {
            return new SigningResult(SigningResult::Pending, 'infocert', externalId: $remote['external_id'] ?? null);
        }

        return new SigningResult(
            SigningResult::Signed,
            'infocert_'.strtolower($envelope->required_level->value),
            pdf: $remote['cms'] ?? null,
            certificateSerial: $remote['serial'] ?? null,
            certificateSubject: $remote['subject'] ?? null,
            externalId: $remote['external_id'] ?? null,
            receipt: $remote['receipt'] ?? null,
            evidence: $remote['evidence'] ?? [],
        );
    }

    protected function assertSandboxChallenge(SignatureRecipient $recipient, SigningInput $input): void
    {
        if ($recipient->auth_method === AuthMethod::SmsOtp && $input->otp !== '000000') {
            throw new SignatureException('otp_invalid');
        }
    }

    public function envelopeIdFromWebhook(string $payload): ?string
    {
        return null;
    }

    public function parseWebhook(string $payload, array $headers, SignatureProviderConfig $config): WebhookEvent
    {
        throw new SignatureException('webhook_not_supported', 404);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: string, cms?: string|null, serial?: string|null, subject?: string|null, external_id?: string|null, receipt?: string|null, evidence?: array<string, mixed>}
     */
    private function callSign(SignatureProviderConfig $config, array $body): array
    {
        try {
            $response = $this->http($config)->withToken($this->accessToken($config))->post($this->url($config, 'sign'), $body);
        } catch (ConnectionException) {
            throw new SignatureException('provider_unavailable', 502);
        }

        if ($response->status() === 422 || $response->status() === 401) {
            $detail = (string) ($response->json('message') ?? $response->json('error') ?? '');

            return ['status' => 'failed', 'evidence' => ['detail' => mb_substr($detail, 0, 200)]];
        }

        $this->ensureSuccessful($response);

        return $this->normalise($response->json() ?? []);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{status: string, cms?: string|null, serial?: string|null, subject?: string|null, external_id?: string|null, receipt?: string|null, evidence?: array<string, mixed>}
     */
    private function normalise(array $json): array
    {
        $status = strtolower((string) ($json['status'] ?? ''));
        $transaction = (string) ($json['transactionId'] ?? $json['id'] ?? '');

        if (in_array($status, ['pending', 'waiting', 'in_progress'], true)) {
            return ['status' => 'pending', 'external_id' => $transaction];
        }

        $signature = $json['signatures'][0]['cms'] ?? $json['signature'] ?? $json['signedDocument'] ?? null;

        if (! in_array($status, ['signed', 'completed', 'ok'], true) || ! is_string($signature) || $signature === '') {
            return ['status' => 'failed', 'evidence' => ['detail' => (string) ($json['message'] ?? $status)]];
        }

        return [
            'status' => 'signed',
            'cms' => (string) base64_decode($signature, true),
            'serial' => isset($json['certificate']['serial']) ? (string) $json['certificate']['serial'] : null,
            'subject' => isset($json['certificate']['subject']) ? (string) $json['certificate']['subject'] : null,
            'external_id' => $transaction,
            'receipt' => $transaction,
            'evidence' => ['timestamp' => (bool) ($json['timestamp'] ?? true), 'ltv' => (bool) ($json['ltv'] ?? true)],
        ];
    }

    private function accessToken(SignatureProviderConfig $config, bool $fresh = false): string
    {
        $key = 'esig:infocert:token:'.$config->id;

        if (! $fresh && is_string($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $response = $this->http($config)->asForm()->post($this->url($config, 'token'), [
                'grant_type' => 'client_credentials',
                'client_id' => $config->credential('client_id'),
                'client_secret' => $config->credential('client_secret'),
            ]);
        } catch (ConnectionException) {
            throw new SignatureException('provider_unavailable', 502);
        }

        $this->ensureSuccessful($response);
        $token = (string) $response->json('access_token');

        if ($token === '') {
            throw new SignatureException('provider_rejected', 422);
        }

        Cache::put($key, $token, max(60, (int) ($response->json('expires_in') ?? 600) - 60));

        return $token;
    }

    private function url(SignatureProviderConfig $config, string $endpoint): string
    {
        $path = (string) (($config->setting('endpoints') ?? [])[$endpoint] ?? self::DefaultEndpoints[$endpoint]);

        return $this->baseUrl($config).'/'.ltrim($path, '/');
    }
}
