<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Services;

use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use ZipArchive;

/**
 * The digital convention pass of a beneficiary: QR code (SVG / PNG), the Apple Wallet .pkpass (pass.json + manifest.json, signed
 * with the configured PKCS#12 certificate) and the Google Wallet "save to wallet" link (a JWT signed RS256 with the service account key).
 * A wallet with no credentials configured is reported unavailable instead of producing a pass the phone would refuse.
 */
final class WalletPassService
{
    private const int QrSize = 360;

    public function qrSvg(string $payload): string
    {
        return (new SvgWriter)->write($this->qrCode($payload))->getString();
    }

    public function qrPng(string $payload): string
    {
        return (new PngWriter)->write($this->qrCode($payload))->getString();
    }

    public function appleAvailable(): bool
    {
        $apple = (array) config('corporate_conventions.wallet.apple');

        return ! empty($apple['pass_type_identifier']) && ! empty($apple['team_identifier'])
            && ! empty($apple['certificate_path']) && is_file((string) $apple['certificate_path'])
            && ! empty($apple['wwdr_certificate_path']) && is_file((string) $apple['wwdr_certificate_path']);
    }

    public function googleAvailable(): bool
    {
        $google = (array) config('corporate_conventions.wallet.google');

        return ! empty($google['issuer_id']) && ! empty($google['service_account_email']) && ! empty($google['private_key']);
    }

    /**
     * The pass.json of the Apple Wallet pass (generic pass with the QR code as barcode).
     *
     * @return array<string, mixed>
     */
    public function applePassJson(ConventionBeneficiaryEnrollment $enrollment, CorporatePartnerConvention $convention): array
    {
        $apple = (array) config('corporate_conventions.wallet.apple');

        return array_filter([
            'formatVersion' => 1,
            'passTypeIdentifier' => $apple['pass_type_identifier'] ?? null,
            'teamIdentifier' => $apple['team_identifier'] ?? null,
            'serialNumber' => $enrollment->wallet_pass_id,
            'organizationName' => (string) config('corporate_conventions.wallet.organization_name'),
            'description' => $convention->name,
            'logoText' => $convention->partner_company_name,
            'backgroundColor' => $this->rgb($convention->primary_color),
            'foregroundColor' => 'rgb(255, 255, 255)',
            'expirationDate' => $enrollment->expires_at?->toIso8601String(),
            'barcodes' => [['format' => 'PKBarcodeFormatQR', 'message' => $enrollment->qr_payload, 'messageEncoding' => 'iso-8859-1']],
            'generic' => [
                'primaryFields' => [['key' => 'name', 'label' => 'NAME', 'value' => $enrollment->full_name]],
                'secondaryFields' => [['key' => 'convention', 'label' => 'CONVENTION', 'value' => $convention->name]],
                'auxiliaryFields' => [['key' => 'valid', 'label' => 'VALID', 'value' => $enrollment->expires_at?->toDateString() ?? '']],
            ],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The signed .pkpass archive.
     */
    public function applePkpass(ConventionBeneficiaryEnrollment $enrollment, CorporatePartnerConvention $convention): string
    {
        if (! $this->appleAvailable()) {
            throw new ConventionException('wallet_apple_unavailable', 503);
        }

        $files = ['pass.json' => (string) json_encode($this->applePassJson($enrollment, $convention), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        $manifest = $this->manifest($files);
        $files['manifest.json'] = $manifest;
        $files['signature'] = $this->sign($manifest);

        return $this->zip($files);
    }

    /**
     * SHA-1 manifest of the files of the pass, as Apple requires.
     *
     * @param  array<string, string>  $files
     */
    public function manifest(array $files): string
    {
        return (string) json_encode(array_map(static fn (string $content): string => sha1($content), $files), JSON_UNESCAPED_SLASHES);
    }

    /**
     * "Add to Google Wallet" link: https://pay.google.com/gp/v/save/<jwt>, or null when Google Wallet is not configured.
     */
    public function googleSaveUrl(ConventionBeneficiaryEnrollment $enrollment, CorporatePartnerConvention $convention): ?string
    {
        if (! $this->googleAvailable()) {
            return null;
        }

        $google = (array) config('corporate_conventions.wallet.google');
        $classId = $google['issuer_id'].'.convention-'.$convention->id;
        $claims = [
            'iss' => $google['service_account_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'origins' => [],
            'payload' => [
                'genericClasses' => [['id' => $classId]],
                'genericObjects' => [[
                    'id' => $google['issuer_id'].'.'.$enrollment->wallet_pass_id,
                    'classId' => $classId,
                    'state' => 'ACTIVE',
                    'cardTitle' => ['defaultValue' => ['language' => 'en', 'value' => $convention->partner_company_name]],
                    'header' => ['defaultValue' => ['language' => 'en', 'value' => $enrollment->full_name]],
                    'subheader' => ['defaultValue' => ['language' => 'en', 'value' => $convention->name]],
                    'hexBackgroundColor' => $convention->primary_color ?? '#1f4e79',
                    'barcode' => ['type' => 'QR_CODE', 'value' => $enrollment->qr_payload],
                ]],
            ],
        ];

        return 'https://pay.google.com/gp/v/save/'.$this->jwt($claims, (string) $google['private_key']);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function jwt(array $claims, string $privateKeyPem): string
    {
        $segments = [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ConventionException('wallet_google_key_invalid', 503);
        }

        return implode('.', [...$segments, $this->base64Url($signature)]);
    }

    private function qrCode(string $payload): QrCode
    {
        return new QrCode(
            data: $payload,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::QrSize,
            margin: 8,
            roundBlockSizeMode: RoundBlockSizeMode::Enlarge,
        );
    }

    private function sign(string $manifest): string
    {
        $apple = (array) config('corporate_conventions.wallet.apple');
        $p12 = (string) file_get_contents((string) $apple['certificate_path']);

        if (! openssl_pkcs12_read($p12, $certs, (string) ($apple['certificate_password'] ?? ''))) {
            throw new ConventionException('wallet_apple_certificate_invalid', 503);
        }

        $manifestPath = tempnam(sys_get_temp_dir(), 'ffm');
        $signaturePath = tempnam(sys_get_temp_dir(), 'ffs');

        try {
            file_put_contents((string) $manifestPath, $manifest);
            $signed = openssl_pkcs7_sign(
                (string) $manifestPath,
                (string) $signaturePath,
                $certs['cert'],
                [$certs['pkey'], (string) ($apple['certificate_password'] ?? '')],
                [],
                PKCS7_BINARY | PKCS7_DETACHED,
                (string) $apple['wwdr_certificate_path'],
            );

            if (! $signed) {
                throw new ConventionException('wallet_apple_certificate_invalid', 503);
            }

            $smime = (string) file_get_contents((string) $signaturePath);

            if (preg_match('/pkcs7-signature.*?\r?\n\r?\n(.*?)\r?\n--/s', $smime, $match) !== 1) {
                throw new ConventionException('wallet_apple_certificate_invalid', 503);
            }

            return (string) base64_decode($match[1], false);
        } finally {
            @unlink((string) $manifestPath);
            @unlink((string) $signaturePath);
        }
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ffp');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
        $binary = (string) file_get_contents($path);
        @unlink($path);

        return $binary;
    }

    private function rgb(?string $hex): string
    {
        $hex = ltrim((string) $hex, '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return 'rgb(31, 78, 121)';
        }

        return sprintf('rgb(%d, %d, %d)', hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
