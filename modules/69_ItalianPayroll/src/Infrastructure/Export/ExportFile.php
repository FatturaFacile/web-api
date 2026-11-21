<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Export;

final readonly class ExportFile
{
    public function __construct(public string $filename, public string $mimeType, public string $content) {}

    public function checksum(): string
    {
        return hash('sha256', $this->content);
    }
}
