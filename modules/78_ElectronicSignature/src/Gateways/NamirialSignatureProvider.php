<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\ElectronicSignature\Contracts\ElectronicSignatureProvider;
use Modules\ElectronicSignature\DTOs\ChallengeResult;
use Modules\ElectronicSignature\DTOs\ConnectionTestResult;
use Modules\ElectronicSignature\DTOs\SigningInput;
use Modules\ElectronicSignature\DTOs\SigningResult;
use Modules\ElectronicSignature\DTOs\WebhookEvent;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Enums\RoutingMode;
use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;
use Modules\ElectronicSignature\Models\SignatureRecipient;
use Modules\ElectronicSignature\Services\PdfSignatureService;
use Modules\ElectronicSignature\Services\WebhookAuthenticator;

/**
 * Namirial eSignAnyWhere (REST API, envelopes): simple, advanced and qualified signatures under eIDAS.
 *
 * Unlike the hash-signing providers, Namirial keeps the envelope: the platform uploads the PDF, describes the recipients
 * with their routing order, the signature fields (page, x, y, width, height) and the authentication (SMS OTP, the Namirial
 * OTP app, Video-ID), and the signers sign on the Namirial page that the platform links to. The progress comes back as
 * webhook events (`recipient_signed`, `recipient_rejected`, `envelope_completed`, `envelope_expired`) that the platform
 * authenticates with an HMAC over the timestamped body, and the final signed PDF is downloaded from the envelope.
 *
 * Credentials (encrypted): `api_key`, `webhook_secret`. The paths of the endpoints (`settings.endpoints.*`) follow the
 * eSignAnyWhere v6 reference and can be overridden per company.
 */
final class NamirialSignatureProvider implements ElectronicSignatureProvider
{
    private const array DefaultEndpoints = [
        'ping' => '/license',
        'upload' => '/file',
        'send' => '/envelope/send',
        'link' => '/envelope/%s/signing-link',
        'status' => '/envelope/%s',
        'download' => '/envelope/%s/download',
        'cancel' => '/envelope/%s/cancel',
    ];

    public function __construct(
        private readonly NativeSignatureProvider $native,
        private readonly PdfSignatureService $pdf,
    ) {}

    public function type(): ProviderType
    {
        return ProviderType::Namirial;
    }

    public function supports(SignatureLevel $level, Jurisdiction $jurisdiction): bool
    {
        return $jurisdiction === Jurisdiction::Eidas;
    }

    public function requiresSequentialRouting(): bool
    {
        return false;
    }

    public function testConnection(SignatureProviderConfig $config): ConnectionTestResult
    {
        if ($config->is_sandbox) {
            return new ConnectionTestResult(true, 'sandbox_ok');
        }

        if ($config->credential('api_key') === null) {
            return new ConnectionTestResult(false, 'credentials_missing');
        }

        try {
            $response = $this->http($config)->get($this->url($config, 'ping'));
        } catch (ConnectionException $exception) {
            return new ConnectionTestResult(false, 'unreachable', mb_substr($exception->getMessage(), 0, 200));
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return new ConnectionTestResult(false, 'credentials_rejected');
        }

        return $response->successful() ? new ConnectionTestResult(true, 'authenticated') : new ConnectionTestResult(false, 'unreachable', 'HTTP '.$response->status());
    }

    public function openEnvelope(SignatureEnvelope $envelope, SignatureProviderConfig $config, string $pdf): string
    {
        if ($config->is_sandbox) {
            return 'namirial-sandbox-'.substr($envelope->verification_code, 0, 12);
        }

        $upload = $this->request($config, fn (PendingRequest $http): Response => $http->asMultipart()->attach('file', $pdf, $envelope->file_name ?? 'document.pdf')->post($this->url($config, 'upload')));
        $fileId = (string) ($upload->json('FileId') ?? $upload->json('fileId'));

        if ($fileId === '') {
            throw new SignatureException('provider_rejected');
        }

        $sizes = $envelope->document_format === 'pades' ? $this->pdf->pageSizes($pdf) : [];
        $activities = [];

        foreach ($envelope->actingRecipients()->get() as $recipient) {
            $activities[] = $this->activity($envelope, $recipient, $sizes);
        }

        $send = $this->request($config, fn (PendingRequest $http): Response => $http->post($this->url($config, 'send'), [
            'Name' => $envelope->title,
            'ExternalId' => $envelope->verification_code,
            'Documents' => [['FileId' => $fileId, 'DocumentNumber' => 1]],
            'Activities' => $activities,
            'SendEnvelope' => true,
            'ExpirationDate' => $envelope->expires_at?->toIso8601String(),
            'CallbackUrl' => url('/api/v1/webhooks/signatures/namirial'),
            'CallbackSigned' => true,
        ]));
        $id = (string) ($send->json('EnvelopeId') ?? $send->json('envelopeId'));

        if ($id === '') {
            throw new SignatureException('provider_rejected');
        }

        return $id;
    }

    public function startChallenge(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): ChallengeResult
    {
        if ($config->is_sandbox) {
            return $this->native->startChallenge($envelope, $recipient, $config);
        }

        $link = $this->request($config, fn (PendingRequest $http): Response => $http->post(
            $this->url($config, 'link', (string) $envelope->external_id),
            ['Email' => $recipient->email, 'Language' => strtolower(substr($envelope->locale, 0, 2))],
        ));
        $url = $link->json('Url') ?? $link->json('url');

        if (! is_string($url) || $url === '') {
            throw new SignatureException('provider_rejected');
        }

        return new ChallengeResult(ChallengeResult::Redirect, url: $url, channel: $recipient->auth_method->value);
    }

    public function sign(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, SigningInput $input): SigningResult
    {
        if (! $config->is_sandbox) {
            // The signer signs on the Namirial page; the result arrives as a webhook event.
            throw new SignatureException('sign_at_provider');
        }

        $result = $this->native->sign($envelope, $recipient, $config, $input);

        return new SigningResult($result->status, 'namirial_sandbox_'.strtolower($envelope->required_level->value), evidence: $result->evidence + ['sandbox' => true]);
    }

    public function poll(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): SigningResult
    {
        if ($config->is_sandbox) {
            throw new SignatureException('provider_not_asynchronous');
        }

        $status = $this->request($config, fn (PendingRequest $http): Response => $http->get($this->url($config, 'status', (string) $envelope->external_id)));
        $recipients = (array) ($status->json('Recipients') ?? []);

        foreach ($recipients as $candidate) {
            if (is_array($candidate) && strcasecmp((string) ($candidate['Email'] ?? ''), $recipient->email) === 0 && strtolower((string) ($candidate['Status'] ?? '')) === 'signed') {
                return new SigningResult(SigningResult::Signed, 'namirial_'.strtolower($envelope->required_level->value), externalId: (string) $envelope->external_id);
            }
        }

        return new SigningResult(SigningResult::Pending, 'namirial', externalId: (string) $envelope->external_id);
    }

    public function finalize(SignatureEnvelope $envelope, SignatureProviderConfig $config, string $workingPdf, array $stamps): string
    {
        if ($config->is_sandbox) {
            return $this->native->finalize($envelope, $config, $workingPdf, $stamps);
        }

        $download = $this->request($config, fn (PendingRequest $http): Response => $http->get($this->url($config, 'download', (string) $envelope->external_id)));

        if ($download->body() === '') {
            throw new SignatureException('provider_rejected');
        }

        return $download->body();
    }

    public function cancel(SignatureEnvelope $envelope, SignatureProviderConfig $config): void
    {
        if ($config->is_sandbox || $envelope->external_id === null) {
            return;
        }

        $this->request($config, fn (PendingRequest $http): Response => $http->post($this->url($config, 'cancel', $envelope->external_id)));
    }

    public function envelopeIdFromWebhook(string $payload): ?string
    {
        $data = json_decode($payload, true);
        $id = is_array($data) ? ($data['EnvelopeId'] ?? $data['envelopeId'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function parseWebhook(string $payload, array $headers, SignatureProviderConfig $config): WebhookEvent
    {
        $data = json_decode($payload, true);

        if (! is_array($data)) {
            throw new SignatureException('webhook_invalid', 400);
        }

        $eventId = (string) ($data['EventId'] ?? $data['eventId'] ?? '');
        $type = strtolower((string) ($data['EventType'] ?? $data['eventType'] ?? ''));
        $envelopeId = (string) ($data['EnvelopeId'] ?? $data['envelopeId'] ?? '');

        if ($eventId === '' || $envelopeId === '') {
            throw new SignatureException('webhook_invalid', 400);
        }

        (new WebhookAuthenticator)->assertAuthentic($payload, $headers, $config->credential('webhook_secret'), 'namirial', $eventId);

        $normalised = match ($type) {
            'recipient_signed' => WebhookEvent::RecipientSigned,
            'recipient_rejected' => WebhookEvent::RecipientRejected,
            'envelope_completed' => WebhookEvent::EnvelopeCompleted,
            'envelope_expired' => WebhookEvent::EnvelopeExpired,
            default => throw new SignatureException('webhook_event_unknown', 422),
        };

        return new WebhookEvent(
            $normalised,
            $eventId,
            $envelopeId,
            isset($data['RecipientEmail']) ? (string) $data['RecipientEmail'] : null,
            isset($data['Reason']) ? (string) $data['Reason'] : null,
            isset($data['CertificateSerial']) ? (string) $data['CertificateSerial'] : null,
            $data,
        );
    }

    /**
     * @param  array<int, array{width: float, height: float}>  $sizes
     * @return array<string, mixed>
     */
    private function activity(SignatureEnvelope $envelope, SignatureRecipient $recipient, array $sizes): array
    {
        $box = $recipient->signature_box;
        $page = $box['page'] ?? 1;
        $size = $sizes[$page] ?? ['width' => 595.28, 'height' => 841.89];
        $name = explode(' ', trim($recipient->full_name), 2);
        $authentication = match ($recipient->auth_method->value) {
            'sms_otp' => ['SmsOtp' => ['PhoneNumber' => $recipient->phone]],
            'mobile_app' => ['MobileApp' => true],
            default => [],
        };

        return [
            'Order' => $envelope->routing_mode === RoutingMode::Sequential ? $recipient->signing_order : 1,
            'Action' => ['Sign' => [
                'RecipientConfiguration' => [
                    'ContactInformation' => [
                        'Email' => $recipient->email,
                        'GivenName' => $name[0],
                        'Surname' => $name[1] ?? $name[0],
                        'PhoneNumber' => $recipient->phone,
                    ],
                    'AuthenticationConfiguration' => $authentication,
                    'SignatureLevel' => $envelope->required_level->value,
                    'Language' => strtolower(substr($envelope->locale, 0, 2)),
                ],
                'Elements' => ['Signature' => $box === null ? [] : [[
                    'Page' => $page,
                    'Position' => ['PageX' => round($box['x'] * $size['width'], 1), 'PageY' => round($box['y'] * $size['height'], 1)],
                    'Size' => ['Width' => round($box['width'] * $size['width'], 1), 'Height' => round($box['height'] * $size['height'], 1)],
                ]]],
            ]],
        ];
    }

    private function http(SignatureProviderConfig $config): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders(['apikey' => (string) $config->credential('api_key', '')])
            ->timeout((int) config('electronic_signature.providers.namirial.timeout', 30))
            ->retry(3, 300, static fn (\Throwable $e): bool => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function request(SignatureProviderConfig $config, callable $call): Response
    {
        try {
            $response = $call($this->http($config));
        } catch (ConnectionException) {
            throw new SignatureException('provider_unavailable', 502);
        }

        if (! $response->successful()) {
            throw new SignatureException($response->serverError() ? 'provider_unavailable' : 'provider_rejected', $response->serverError() ? 502 : 422, ['status' => $response->status(), 'detail' => mb_substr($response->body(), 0, 300)]);
        }

        return $response;
    }

    private function url(SignatureProviderConfig $config, string $endpoint, ?string $argument = null): string
    {
        $path = (string) (($config->setting('endpoints') ?? [])[$endpoint] ?? self::DefaultEndpoints[$endpoint]);
        $base = rtrim((string) ($config->setting('base_url') ?: config('electronic_signature.providers.namirial.base_url')), '/');

        return $base.'/'.ltrim($argument !== null ? sprintf($path, rawurlencode($argument)) : $path, '/');
    }
}
