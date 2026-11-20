<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Enums;

/**
 * How the fields of a job were obtained. Only VisionLlmOcr and HybridQrPlusOcr spend OCR/LLM capacity.
 */
enum ExtractionMethod: string
{
    case EmbeddedXmlOrQr = 'embedded_xml_or_qr';
    case HybridQrPlusOcr = 'hybrid_qr_plus_ocr';
    case VisionLlmOcr = 'vision_llm_ocr';

    public function usesOcrProvider(): bool
    {
        return $this !== self::EmbeddedXmlOrQr;
    }
}
