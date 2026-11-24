<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

/**
 * Encryption at rest of backup archives. The ZIP is split in 1 MiB chunks, each sealed with AES-256-GCM (authenticated
 * encryption) under a fresh random IV. The additional authenticated data binds every chunk to its position and marks
 * the last one, so reordering, truncating or editing the file makes decryption fail. File layout: the magic header,
 * then for each chunk `length (4 bytes) | iv (12) | tag (16) | ciphertext`.
 *
 * The key comes from `backup.encryption.key` (BACKUP_ENCRYPTION_KEY, `base64:` prefixed or any secret), falling back to a
 * key derived from APP_KEY. `backup.encryption.previous_keys` are still tried on decryption (key rotation). Archives
 * without the magic header (made before encryption existed) are plain ZIPs and are returned untouched by `decrypt`.
 */
class BackupArchiveEncryptor
{
    public const string MAGIC = 'FFBKENC1';

    private const int CHUNK = 1048576;

    private const string CIPHER = 'aes-256-gcm';

    public function enabled(): bool
    {
        return (bool) config('backup.encryption.enabled', true);
    }

    public function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $head === self::MAGIC;
    }

    public function encrypt(string $source, string $target): void
    {
        $in = $this->open($source, 'rb');
        $out = $this->open($target, 'wb');
        $key = $this->keys()[0];

        try {
            fwrite($out, self::MAGIC);
            $index = 0;
            $chunk = (string) fread($in, self::CHUNK);

            do {
                $next = (string) fread($in, self::CHUNK);
                $last = $next === '' && feof($in);
                $iv = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt($chunk, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($index, $last), 16);

                if ($cipher === false) {
                    throw new RuntimeException('Backup archive encryption failed.');
                }

                fwrite($out, pack('N', strlen($cipher)).$iv.$tag.$cipher);
                $chunk = $next;
                $index++;
            } while (! $last);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Decrypt `$source` into `$target`; a plain (legacy) archive is copied as is.
     *
     * @throws RuntimeException when the file is encrypted but tampered with or no key opens it
     */
    public function decrypt(string $source, string $target): void
    {
        if (! $this->isEncrypted($source)) {
            if (! copy($source, $target)) {
                throw new RuntimeException('Backup archive could not be copied.');
            }

            return;
        }

        $in = $this->open($source, 'rb');
        $out = $this->open($target, 'wb');

        try {
            fseek($in, strlen(self::MAGIC));
            $index = 0;

            while (! feof($in)) {
                $header = (string) fread($in, 4);

                if ($header === '') {
                    break;
                }

                $length = (int) (unpack('N', $header)[1] ?? -1);
                $iv = (string) fread($in, 12);
                $tag = (string) fread($in, 16);
                $cipher = $length > 0 ? (string) fread($in, $length) : '';

                if (strlen($header) !== 4 || $length < 0 || $length > self::CHUNK || strlen($iv) !== 12 || strlen($tag) !== 16 || strlen($cipher) !== $length) {
                    throw new RuntimeException('Backup archive is truncated or corrupted.');
                }

                $last = (string) fread($in, 1) === '' && feof($in);
                if (! $last) {
                    fseek($in, -1, SEEK_CUR);
                }

                fwrite($out, $this->openChunk($cipher, $iv, $tag, $index, $last));
                $index++;
            }

            if ($index === 0) {
                throw new RuntimeException('Backup archive is empty.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function openChunk(string $cipher, string $iv, string $tag, int $index, bool $last): string
    {
        foreach ($this->keys() as $key) {
            $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($index, $last));

            if ($plain !== false) {
                return $plain;
            }
        }

        throw new RuntimeException('Backup archive failed authentication: wrong key or tampered file.');
    }

    private function aad(int $index, bool $last): string
    {
        return self::MAGIC.pack('J', $index).($last ? "\x01" : "\x00");
    }

    /**
     * @return non-empty-list<string> 32-byte keys, the current one first
     */
    private function keys(): array
    {
        $configured = array_merge([(string) config('backup.encryption.key', '')], (array) config('backup.encryption.previous_keys', []));
        $keys = [];

        foreach ($configured as $secret) {
            if (is_string($secret) && $secret !== '') {
                $keys[] = $this->derive($secret);
            }
        }

        $appKey = (string) config('app.key');
        $keys[] = $this->derive(str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey);

        return $keys;
    }

    private function derive(string $secret): string
    {
        if (str_starts_with($secret, 'base64:')) {
            $secret = (string) base64_decode(substr($secret, 7), true);
        }

        return hash_hkdf('sha256', $secret, 32, 'fattura-facile/tenant-backup-encryption/v1');
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        return $handle;
    }
}
