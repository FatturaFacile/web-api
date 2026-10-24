<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\SdiProviderException;

interface SdiProvider
{
    /**
     * Stable provider identifier stored on logs and UUID links.
     */
    public function name(): string;

    /**
     * Transmit a FatturaPA XML document to the Exchange System (SDI).
     *
     * @return array{uuid: string, response: array<string, mixed>}
     *
     * @throws SdiProviderException when the provider rejects or cannot process the transmission
     */
    public function send(string $fileName, string $xml): array;
}
