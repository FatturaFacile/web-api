<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;

/**
 * The X.509 material the platform seals documents with: the PKCS#12 certificate of the company when the provider
 * configuration has one, otherwise a key pair the platform generates and keeps encrypted for the company (a self-signed
 * seal: it proves integrity and origin inside the platform, it is not a certificate of a trust service provider).
 */
final class PlatformSealService
{
    /**
     * @return array{certificate: string, key: string, chain: string, system_managed: bool}
     */
    public function material(SignatureProviderConfig $config): array
    {
        $disk = Storage::disk((string) config('electronic_signature.disk', 'local'));

        if ($config->certificate_path !== null && $config->certificate_path !== '') {
            $contents = $disk->get($config->certificate_path);
            $certificates = [];

            if ($contents === null || ! openssl_pkcs12_read($contents, $certificates, (string) $config->credential('certificate_password', ''))) {
                throw new SignatureException('certificate_unreadable', 422);
            }

            return [
                'certificate' => (string) $certificates['cert'],
                'key' => (string) $certificates['pkey'],
                'chain' => implode("\n", array_map('strval', (array) ($certificates['extracerts'] ?? []))),
                'system_managed' => false,
            ];
        }

        $path = 'signatures/'.($config->tenant_id ?? 'global').'/keys/seal.enc';

        if ($disk->exists($path)) {
            try {
                /** @var array{certificate: string, key: string} $stored */
                $stored = json_decode(Crypt::decryptString((string) $disk->get($path)), true, 512, JSON_THROW_ON_ERROR);

                return ['certificate' => $stored['certificate'], 'key' => $stored['key'], 'chain' => '', 'system_managed' => true];
            } catch (DecryptException|\JsonException) {
                // A key encrypted under a rotated application key cannot be read: a new seal is issued below.
            }
        }

        $generated = $this->generate($this->subjectName($config));
        $disk->put($path, Crypt::encryptString((string) json_encode($generated, JSON_THROW_ON_ERROR)));

        return $generated + ['chain' => '', 'system_managed' => true];
    }

    /**
     * Validates a PKCS#12 file and its password; used when a certificate is uploaded.
     */
    public function assertReadable(string $pkcs12, string $password): void
    {
        $certificates = [];

        if (! openssl_pkcs12_read($pkcs12, $certificates, $password) || ! isset($certificates['cert'], $certificates['pkey'])) {
            throw new SignatureException('certificate_unreadable', 422);
        }
    }

    /**
     * @return array{certificate: string, key: string}
     */
    public function generate(string $commonName): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $key = @openssl_pkey_new($options);

        if ($key === false) {
            // Some workstations (Windows) have no default openssl.cnf: a minimal one is enough to generate a key.
            $options['config'] = $this->minimalOpenSslConfig();
            $key = openssl_pkey_new($options);
        }

        if ($key === false) {
            throw new SignatureException('seal_failed', 500);
        }

        $subject = ['commonName' => mb_substr($commonName, 0, 64), 'organizationName' => 'FatturaFacile'];
        $csr = openssl_csr_new($subject, $key, $options);
        $certificate = $csr === false ? false : openssl_csr_sign($csr, null, $key, 365 * 5, $options, random_int(1, PHP_INT_MAX));

        if ($certificate === false) {
            throw new SignatureException('seal_failed', 500);
        }

        $certificatePem = '';
        $keyPem = '';
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem, null, $options);

        return ['certificate' => $certificatePem, 'key' => $keyPem];
    }

    private function subjectName(SignatureProviderConfig $config): string
    {
        $tenant = $config->tenant_id !== null ? Tenant::query()->find($config->tenant_id) : null;
        $name = (string) ($tenant->legal_name ?? $tenant->name ?? 'FatturaFacile');

        return $name.' (seal)';
    }

    private function minimalOpenSslConfig(): string
    {
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fatturafacile-openssl.cnf';

        if (! is_file($file)) {
            file_put_contents($file, "[req]\ndistinguished_name = dn\n[dn]\n");
        }

        return $file;
    }
}
