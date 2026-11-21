<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Exceptions;

use RuntimeException;

/**
 * A business rule of the extraction module was violated (mapped to HTTP 422).
 */
class OcrExtractionException extends RuntimeException {}
