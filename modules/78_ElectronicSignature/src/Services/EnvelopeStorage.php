<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;

/**
 * The files of an envelope on the private disk: the original, the working copy that accumulates the signatures, the signed
 * document, the pending signature of a recipient and the certificate of completion. Nothing here is publicly addressable.
 */
final class EnvelopeStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('electronic_signature.disk', 'local'));
    }

    public function directory(SignatureEnvelope $envelope): string
    {
        return 'signatures/'.($envelope->tenant_id ?? 'global').'/'.$envelope->id;
    }

    public function put(string $path, string $content): void
    {
        if (! $this->disk()->put($path, $content)) {
            throw new SignatureException('storage_failed', 500);
        }
    }

    public function get(?string $path): string
    {
        $content = $path !== null ? $this->disk()->get($path) : null;

        return $content ?? throw new SignatureException('document_missing', 404);
    }

    public function original(SignatureEnvelope $envelope): string
    {
        return $this->get($envelope->original_path);
    }

    /**
     * The document the next signature applies to: the working copy once signatures were added, the original before.
     */
    public function working(SignatureEnvelope $envelope): string
    {
        return $this->get($envelope->working_path ?? $envelope->original_path);
    }

    public function signed(SignatureEnvelope $envelope): string
    {
        return $this->get($envelope->signed_path);
    }

    public function extension(SignatureEnvelope $envelope): string
    {
        return match ($envelope->document_format) {
            'cades' => 'p7m',
            'xades' => 'xml',
            default => 'pdf',
        };
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->disk()->delete($path);
        }
    }
}
