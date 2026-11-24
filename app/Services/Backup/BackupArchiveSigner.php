<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Server signature of backup archives. `manifest.sig` holds the HMAC-SHA256 of the exact bytes of `manifest.json`,
 * with a key derived from the application key; the manifest in turn fixes the SHA-256 of `data.json` and of every
 * file. An archive is therefore restorable only if this server (or one sharing its APP_KEY, current or previous)
 * produced it, unchanged.
 */
class BackupArchiveSigner
{
    public const string SIGNATURE_ENTRY = 'manifest.sig';

    private const string KEY_CONTEXT = 'fattura-facile/tenant-backup-archive/v1';

    public function __construct(private readonly Encrypter $encrypter) {}

    public function sign(string $manifest): string
    {
        return hash_hmac('sha256', $manifest, $this->key($this->encrypter->getKey()));
    }

    /**
     * Whether the signature matches the manifest under the current key or one of the previous keys (APP_PREVIOUS_KEYS).
     */
    public function verify(string $manifest, ?string $signature): bool
    {
        $signature = strtolower(trim((string) $signature));

        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return false;
        }

        foreach ($this->keys() as $key) {
            if (hash_equals(hash_hmac('sha256', $manifest, $this->key($key)), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        $previous = method_exists($this->encrypter, 'getPreviousKeys') ? $this->encrypter->getPreviousKeys() : [];

        return array_values(array_merge([$this->encrypter->getKey()], $previous));
    }

    /**
     * A key used for archives only, so the signature never exposes or reuses the encryption key itself.
     */
    private function key(string $applicationKey): string
    {
        return hash_hmac('sha256', self::KEY_CONTEXT, $applicationKey, true);
    }
}
