<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Enums;

/**
 * Engine that reads unstructured documents. Local engines keep the file on the tenant's infrastructure (Swiss nLPD, EU
 * GDPR); cloud engines need the tenant's explicit consent to external AI providers.
 */
enum OcrProviderType: string
{
    case OllamaLocal = 'ollama_local';
    case LayoutLmV3Local = 'layoutlmv3_local';
    case TesseractLocal = 'tesseract_local';
    case GeminiCloud = 'gemini_cloud';
    case OpenAiCloud = 'openai_cloud';
    case AzureDocumentIntelligence = 'azure_document_intelligence';

    public function isCloud(): bool
    {
        return match ($this) {
            self::GeminiCloud, self::OpenAiCloud, self::AzureDocumentIntelligence => true,
            default => false,
        };
    }
}
