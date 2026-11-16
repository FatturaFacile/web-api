<?php

declare(strict_types=1);

namespace Modules\Documents\Contracts;

use Modules\Documents\DTOs\OcrResult;
use Modules\Documents\Exceptions\OcrFailedException;

/**
 * A pluggable OCR engine. Engines are tagged `dms.ocr_engines` in the container; the pipeline picks the first one that
 * supports the format. An engine runs outside any database transaction and may call an external service.
 */
interface OcrEngine
{
    /**
     * Stable identifier stored on the job.
     */
    public function name(): string;

    /**
     * Whether the engine can read this MIME type.
     */
    public function supports(string $mimeType): bool;

    /**
     * @throws OcrFailedException when the file cannot be read
     */
    public function recognize(string $contents, string $mimeType): OcrResult;
}
