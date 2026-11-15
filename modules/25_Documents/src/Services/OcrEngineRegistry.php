<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use Modules\Documents\Contracts\OcrEngine;
use Modules\Documents\Exceptions\OcrFailedException;

/**
 * The OCR engines of the installation, in registration order (container tag `dms.ocr_engines`). The first engine that
 * supports the format reads the file.
 */
final readonly class OcrEngineRegistry
{
    /**
     * @param  iterable<OcrEngine>  $engines
     */
    public function __construct(private iterable $engines) {}

    /**
     * @throws OcrFailedException when no engine supports the format
     */
    public function engineFor(string $mimeType): OcrEngine
    {
        foreach ($this->engines as $engine) {
            if ($engine->supports($mimeType)) {
                return $engine;
            }
        }

        throw new OcrFailedException(__('documents_errors.ocr.unsupported'));
    }

    /**
     * Names of the registered engines.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->engines as $engine) {
            $names[] = $engine->name();
        }

        return $names;
    }
}
