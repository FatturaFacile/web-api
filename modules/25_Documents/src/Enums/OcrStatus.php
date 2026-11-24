<?php

declare(strict_types=1);

namespace Modules\Documents\Enums;

/**
 * Extraction status of an OCR job.
 */
enum OcrStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
