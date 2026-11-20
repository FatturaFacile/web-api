<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Exceptions;

/**
 * The extraction job (or a record it refers to) does not exist for the tenant (mapped to HTTP 404).
 */
final class OcrExtractionNotFoundException extends OcrExtractionException {}
