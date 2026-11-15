<?php

declare(strict_types=1);

namespace Modules\Documents\Exceptions;

use RuntimeException;

/**
 * An OCR engine could not read a file, or no engine supports its format.
 */
final class OcrFailedException extends RuntimeException {}
