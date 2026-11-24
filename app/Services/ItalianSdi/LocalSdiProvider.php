<?php

declare(strict_types=1);

namespace App\Services\ItalianSdi;

use App\Contracts\SdiProvider;
use App\Exceptions\SdiProviderException;
use Illuminate\Support\Str;

/**
 * Offline stand-in for a real SDI gateway: validates the payload, assigns a UUID and performs no network call.
 * Replace the container binding of {@see SdiProvider} with a real gateway implementation for production use.
 */
class LocalSdiProvider implements SdiProvider
{
    public function name(): string
    {
        return 'local';
    }

    public function send(string $fileName, string $xml): array
    {
        if (trim($xml) === '' || ! str_contains($xml, 'FatturaElettronica')) {
            throw new SdiProviderException(__('sdi.invalid_fatturapa_xml'));
        }

        return [
            'uuid' => (string) Str::uuid(),
            'response' => ['file_name' => $fileName, 'bytes' => strlen($xml), 'accepted' => true],
        ];
    }
}
