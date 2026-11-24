<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Contracts;

interface TextRecognizerInterface
{
    /**
     * The text of a file, or null when this recognizer cannot read that format.
     */
    public function textOf(string $contents, ?string $mimeType): ?string;
}
