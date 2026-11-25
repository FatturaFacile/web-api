<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Modules\ElectronicSignature\DTOs\ChallengeResult;
use Modules\ElectronicSignature\DTOs\ConnectionTestResult;
use Modules\ElectronicSignature\DTOs\PreparedPdf;
use Modules\ElectronicSignature\DTOs\SigningInput;
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
 * Swisscom Trust Services, All-in Signing Service (AIS): advanced and qualified signatures valid under ZertES (Switzerland)
 * and eIDAS (EU).
 *
 * The REST/JSON service is called with mutual TLS (the client certificate and key of the AIS account) and the claimed
 * identity of the account. The platform sends only the SHA-256 digest of the ByteRange; the signer confirms with the
 * step-up authorisation (Mobile ID or SMS to the MSISDN of the recipient) and an on-demand certificate is issued for them.
 * The CMS that comes back carries the RFC 3161 time stamp and the CRL / OCSP data (`AddRevocationInformation`), which
 * makes the signature PAdES-B-LT. When the step-up is still open the service answers "pending" with a response id that is
 * polled (`/pending`).
 *
 * Credentials (encrypted): `claimed_identity`, `claimed_identity_qes` (identity of the qualified service, defaults to the
 * first), `client_certificate`, `client_key`, `client_key_password`, `distinguished_name_suffix`, `webhook_secret`.
 */
final class SwisscomSignatureProvider extends HashSigningProvider
{
    private const string Profile = 'http://ais.swisscom.ch/1.1';

    public function type(): ProviderType
    {
        return ProviderType::Swisscom;
    }

    public function supports(SignatureLevel $level, Jurisdiction $jurisdiction): bool
    {
        return $level !== SignatureLevel::Fes;
    }

    public function testConnection(SignatureProviderConfig $config): ConnectionTestResult
    {
        if ($config->is_sandbox) {
            return new ConnectionTestResult(true, 'sandbox_ok');
        }

        if ($config->credential('claimed_identity') === null || $config->credential('client_certificate') === null || $config->credential('client_key') === null) {
            return new ConnectionTestResult(false, 'credentials_missing');
        }

        try {
            $this->withClientCertificate($config, function (array $options) use ($config): void {
                // AIS has no health endpoint: reaching the host with the client certificate proves the TLS and network side.
                $this->http($config, $options)->get($this->baseUrl($config));
            });
        } catch (ConnectionException $exception) {
            return new ConnectionTestResult(false, 'unreachable', mb_substr($exception->getMessage(), 0, 200));
        }

        return new ConnectionTestResult(true, 'reachable');
    }

    public function startChallenge(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): ChallengeResult
    {
        if (! in_array($recipient->auth_method, [AuthMethod::SmsOtp, AuthMethod::MobileApp, AuthMethod::SwissId], true)) {
            throw new SignatureException('auth_method_not_supported');
        }

        if ($recipient->phone === null || $recipient->phone === '') {
            throw new SignatureException('phone_required');
        }

        // The step-up message is pushed to the phone when the signature is requested.
        return new ChallengeResult(ChallengeResult::Push, channel: $recipient->auth_method === AuthMethod::SmsOtp ? 'sms' : 'mobile_id');
    }

    protected function requestSignature(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, PreparedPdf $prepared, SigningInput $input): array
    {
        $body = ['SignRequest' => [
            '@RequestID' => (string) Str::uuid(),
            '@Profile' => self::Profile,
            'OptionalInputs' => [
                'ClaimedIdentity' => ['Name' => $this->claimedIdentity($envelope, $config)],
                'SignatureType' => 'urn:ietf:rfc:3369',
                'AdditionalProfile' => [
                    'http://ais.swisscom.ch/1.0/profiles/ondemandcertificate',
                    'urn:oasis:names:tc:dss:1.0:profiles:timestamping',
                    'urn:oasis:names:tc:dss:1.0:profiles:asynchronousprocessing',
                ],
                'AddTimestamp' => ['@Type' => 'urn:ietf:rfc:3161'],
                'sc.AddRevocationInformation' => ['@Type' => 'BOTH'],
                'sc.CertificateRequest' => [
                    'sc.DistinguishedName' => $this->distinguishedName($recipient, $config),
                    'sc.StepUpAuthorisation' => ['sc.Phone' => [
                        'sc.MSISDN' => ltrim((string) $recipient->phone, '+'),
                        'sc.Message' => mb_substr($envelope->title, 0, 100),
                        'sc.Language' => strtolower(substr($envelope->locale, 0, 2)),
                        'sc.SerialNumber' => '',
                    ]],
                ],
            ],
            'InputDocuments' => ['DocumentHash' => [
                'dsig.DigestMethod' => ['@Algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256'],
                'dsig.DigestValue' => $prepared->digestBase64(),
            ]],
        ]];

        return $this->call($config, '/sign', $body);
    }

    protected function pollSignature(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, PreparedPdf $prepared): array
    {
        return $this->call($config, '/pending', ['async.PendingRequest' => [
            '@Profile' => self::Profile,
            'OptionalInputs' => [
                'ClaimedIdentity' => ['Name' => $this->claimedIdentity($envelope, $config)],
                'async.ResponseID' => (string) $recipient->external_id,
            ],
        ]]);
    }

    public function envelopeIdFromWebhook(string $payload): ?string
    {
        return null;
    }

    public function parseWebhook(string $payload, array $headers, SignatureProviderConfig $config): WebhookEvent
    {
        // AIS answers synchronously or through /pending; it has no callbacks.
        throw new SignatureException('webhook_not_supported', 404);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: string, cms?: string|null, serial?: string|null, subject?: string|null, external_id?: string|null, receipt?: string|null, evidence?: array<string, mixed>}
     */
    private function call(SignatureProviderConfig $config, string $path, array $body): array
    {
        try {
            $response = $this->withClientCertificate($config, fn (array $options) => $this->http($config, $options)->post($this->baseUrl($config).$path, $body));
        } catch (ConnectionException) {
            throw new SignatureException('provider_unavailable', 502);
        }

        $json = $response->json() ?? [];
        $result = (string) data_get($json, 'SignResponse.Result.ResultMajor', '');
        $minor = (string) data_get($json, 'SignResponse.Result.ResultMinor', '');
        $requestId = (string) data_get($json, 'SignResponse.@RequestID', '');
        $responseId = (string) (data_get($json, 'SignResponse.OptionalOutputs.async.ResponseID') ?? '');

        if (str_ends_with($result, ':Pending') || str_contains($result, 'resultmajor:Pending')) {
            return ['status' => 'pending', 'external_id' => $responseId, 'evidence' => ['request_id' => $requestId]];
        }

        if (! $response->successful() && $result === '') {
            $this->ensureSuccessful($response);
        }

        $signature = data_get($json, 'SignResponse.SignatureObject.Base64Signature.$');

        if (! str_ends_with($result, ':Success') || ! is_string($signature) || $signature === '') {
            return ['status' => 'failed', 'evidence' => ['detail' => $minor !== '' ? $minor : $result, 'request_id' => $requestId]];
        }

        $certificate = (string) data_get($json, 'SignResponse.OptionalOutputs.sc.CertificateInfo.sc.SerialNumber', '');

        return [
            'status' => 'signed',
            'cms' => (string) base64_decode($signature, true),
            'serial' => $certificate !== '' ? $certificate : null,
            'subject' => data_get($json, 'SignResponse.OptionalOutputs.sc.CertificateInfo.sc.Subject'),
            'external_id' => $responseId !== '' ? $responseId : $requestId,
            'receipt' => $requestId,
            'evidence' => ['request_id' => $requestId, 'timestamp' => true, 'revocation_information' => true],
        ];
    }

    private function claimedIdentity(SignatureEnvelope $envelope, SignatureProviderConfig $config): string
    {
        $standard = (string) $config->credential('claimed_identity', '');

        if ($envelope->required_level === SignatureLevel::Feq) {
            return $config->credential('claimed_identity_qes', $standard) ?? $standard;
        }

        return $standard;
    }

    private function distinguishedName(SignatureRecipient $recipient, SignatureProviderConfig $config): string
    {
        $country = $recipient->identity_metadata['country'] ?? 'CH';
        $suffix = (string) $config->credential('distinguished_name_suffix', '');
        $name = str_replace([',', '=', '+', '"', '\\', '<', '>', ';'], ' ', $recipient->full_name);

        return 'cn='.$name.($suffix !== '' ? ','.$suffix : '').',c='.strtoupper(substr((string) $country, 0, 2));
    }

    /**
     * Runs a callback with the client certificate and key of the account written to temporary files (Guzzle takes paths).
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $callback
     * @return T
     */
    private function withClientCertificate(SignatureProviderConfig $config, callable $callback): mixed
    {
        $certificate = (string) $config->credential('client_certificate', '');
        $key = (string) $config->credential('client_key', '');

        if ($certificate === '' || $key === '') {
            throw new SignatureException('credentials_missing');
        }

        $certFile = tempnam(sys_get_temp_dir(), 'aiscrt');
        $keyFile = tempnam(sys_get_temp_dir(), 'aiskey');

        try {
            file_put_contents($certFile, $certificate);
            file_put_contents($keyFile, $key);
            $password = $config->credential('client_key_password');

            return $callback(['cert' => $certFile, 'ssl_key' => $password !== null ? [$keyFile, $password] : $keyFile]);
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }
    }
}
