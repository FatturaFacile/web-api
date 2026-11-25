<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Gateways;

use DateTimeImmutable;
use Modules\ElectronicSignature\Contracts\ElectronicSignatureProvider;
use Modules\ElectronicSignature\DTOs\ChallengeResult;
use Modules\ElectronicSignature\DTOs\ConnectionTestResult;
use Modules\ElectronicSignature\DTOs\SignatureAppearance;
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
use Modules\ElectronicSignature\Services\PdfSignatureService;
use Modules\ElectronicSignature\Services\PlatformSealService;
use Modules\ElectronicSignature\Services\SignatureNotifier;
use Modules\SDI\Signature\LocalPkcs12SignatureProvider;

/**
 * The built-in engine: simple (FES) and advanced (FEA) signatures at no certification authority cost.
 *
 * Each signer is identified by their personal link (FES) and, for FEA, by a one-time code sent by SMS; the signature
 * (typed, drawn or a click) and the evidence (IP address, device, consent, code check) go to the audit trail. When the last
 * signer has signed the platform draws the stamps with the verification QR code and seals the PDF with the X.509 certificate
 * of the company (PKCS#12) or a key the platform manages for it, so any later change of the file is detectable.
 *
 * A qualified signature (FEQ) needs a qualified trust service provider: this engine refuses it.
 */
final class NativeSignatureProvider implements ElectronicSignatureProvider
{
    public function __construct(
        private readonly PdfSignatureService $pdf,
        private readonly PlatformSealService $seal,
        private readonly SignatureNotifier $notifier,
    ) {}

    public function type(): ProviderType
    {
        return ProviderType::Native;
    }

    public function supports(SignatureLevel $level, Jurisdiction $jurisdiction): bool
    {
        return $level !== SignatureLevel::Feq;
    }

    public function requiresSequentialRouting(): bool
    {
        return false;
    }

    public function testConnection(SignatureProviderConfig $config): ConnectionTestResult
    {
        try {
            $material = $this->seal->material($config);
            $parsed = openssl_x509_parse($material['certificate']);
            $subject = is_array($parsed) ? (string) ($parsed['subject']['CN'] ?? '') : '';

            return new ConnectionTestResult(true, $material['system_managed'] ? 'seal_system_managed' : 'seal_ready', $subject);
        } catch (SignatureException $exception) {
            return new ConnectionTestResult(false, $exception->errorCode());
        }
    }

    public function openEnvelope(SignatureEnvelope $envelope, SignatureProviderConfig $config, string $pdf): ?string
    {
        return null;
    }

    public function startChallenge(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): ChallengeResult
    {
        return match ($recipient->auth_method) {
            AuthMethod::EmailLink => new ChallengeResult(ChallengeResult::None),
            AuthMethod::SmsOtp => $this->sendCode($envelope, $recipient, $config),
            default => throw new SignatureException('auth_method_not_supported'),
        };
    }

    public function sign(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config, SigningInput $input): SigningResult
    {
        if ($recipient->auth_method === AuthMethod::SmsOtp) {
            $this->checkCode($recipient, (string) $input->otp);
        } elseif ($recipient->auth_method !== AuthMethod::EmailLink) {
            throw new SignatureException('auth_method_not_supported');
        }

        $level = $envelope->required_level->value;

        return new SigningResult(
            status: SigningResult::Signed,
            method: 'native_'.strtolower($level),
            evidence: [
                'identity_check' => $recipient->auth_method->value,
                'signature_method' => $input->signatureMethod,
                'level' => $level,
                'jurisdiction' => $envelope->jurisdiction->value,
            ],
        );
    }

    public function poll(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): SigningResult
    {
        throw new SignatureException('provider_not_asynchronous');
    }

    public function finalize(SignatureEnvelope $envelope, SignatureProviderConfig $config, string $workingPdf, array $stamps): string
    {
        $material = $this->seal->material($config);

        if ($envelope->document_format !== 'pades') {
            return $this->sealStructured($envelope, $material, $workingPdf);
        }

        $stamped = $this->pdf->stamp($workingPdf, $stamps);
        $parsed = openssl_x509_parse($material['certificate']);
        $sealer = is_array($parsed) ? (string) ($parsed['subject']['CN'] ?? 'FatturaFacile') : 'FatturaFacile';

        return $this->pdf->signWithKey(
            $stamped,
            new SignatureAppearance($sealer, new DateTimeImmutable, 'Sealed by FatturaFacile '.$envelope->verification_code),
            $material['certificate'],
            $material['key'],
            $material['chain'],
        );
    }

    /**
     * CAdES-BES (.p7m) or XAdES-BES (XML) of a document that is not a PDF, with the signer of the SDI module.
     *
     * @param  array{certificate: string, key: string, chain: string, system_managed: bool}  $material
     */
    private function sealStructured(SignatureEnvelope $envelope, array $material, string $content): string
    {
        $password = bin2hex(random_bytes(8));
        $pkcs12 = '';

        if (! openssl_pkcs12_export($material['certificate'], $pkcs12, $material['key'], $password)) {
            throw new SignatureException('seal_failed', 500);
        }

        try {
            $signer = new LocalPkcs12SignatureProvider($pkcs12, $password);
            $name = $envelope->file_name ?? 'document.xml';

            return $envelope->document_format === 'xades' ? $signer->signXades($content, $name)->content : $signer->signCades($content, $name)->content;
        } catch (\Throwable) {
            throw new SignatureException('seal_failed', 500);
        }
    }

    public function cancel(SignatureEnvelope $envelope, SignatureProviderConfig $config): void {}

    public function envelopeIdFromWebhook(string $payload): ?string
    {
        return null;
    }

    public function parseWebhook(string $payload, array $headers, SignatureProviderConfig $config): WebhookEvent
    {
        throw new SignatureException('webhook_not_supported', 404);
    }

    private function sendCode(SignatureEnvelope $envelope, SignatureRecipient $recipient, SignatureProviderConfig $config): ChallengeResult
    {
        if ($recipient->phone === null || $recipient->phone === '') {
            throw new SignatureException('phone_required');
        }

        $digits = (int) config('electronic_signature.otp.digits', 6);
        $code = str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes((int) config('electronic_signature.otp.ttl_minutes', 10));

        $recipient->forceFill([
            'otp_hash' => $this->hashCode($recipient, $code),
            'otp_expires_at' => $expiresAt,
            'otp_attempts' => 0,
        ])->save();

        $this->notifier->sendOtp($envelope, $recipient, $code, $config->is_sandbox);

        return new ChallengeResult(
            ChallengeResult::Otp,
            expiresAt: $expiresAt->toDateTimeImmutable(),
            channel: 'sms',
            sandboxCode: $config->is_sandbox && ! app()->isProduction() ? $code : null,
        );
    }

    private function checkCode(SignatureRecipient $recipient, string $code): void
    {
        $max = (int) config('electronic_signature.otp.max_attempts', 5);

        if ($recipient->otp_hash === null || $recipient->otp_expires_at === null || $recipient->otp_expires_at->isPast()) {
            throw new SignatureException('otp_expired');
        }

        if ($recipient->otp_attempts >= $max) {
            throw new SignatureException('otp_locked', 429);
        }

        if ($code === '' || ! hash_equals($recipient->otp_hash, $this->hashCode($recipient, $code))) {
            $recipient->forceFill(['otp_attempts' => $recipient->otp_attempts + 1])->save();

            throw new SignatureException('otp_invalid');
        }

        // A code is good once.
        $recipient->forceFill(['otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0])->save();
    }

    private function hashCode(SignatureRecipient $recipient, string $code): string
    {
        return hash_hmac('sha256', $recipient->id.':'.$code, (string) config('app.key'));
    }
}
