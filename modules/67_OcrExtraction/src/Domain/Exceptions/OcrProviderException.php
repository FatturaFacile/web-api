<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Exceptions;

/**
 * An OCR / Vision-LLM provider is not allowed, not configured or failed to read the document.
 */
final class OcrProviderException extends OcrExtractionException {}
