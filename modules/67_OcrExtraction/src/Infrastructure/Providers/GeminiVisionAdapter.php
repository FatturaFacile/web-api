<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Domain\Exceptions\OcrProviderException;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionRequest;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionResult;

/**
 * Google Gemini (cloud). Only used when the tenant consented to external AI providers and stored its own API key. PDFs
 * and images are sent as they are; the key travels in a header, never in the URL.
 */
final class GeminiVisionAdapter extends AbstractVisionAdapter
{
    public function type(): OcrProviderType
    {
        return OcrProviderType::GeminiCloud;
    }

    public function extract(OcrExtractionRequest $request): OcrExtractionResult
    {
        $key = $this->requiredSetting($request->settings, 'api_key');
        $model = $this->setting($request->settings, 'model', (string) config('ocr_extraction.gemini.model'));
        $base = rtrim((string) config('ocr_extraction.gemini.endpoint'), '/');

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout((int) config('ocr_extraction.timeout_seconds', 120))
                ->post("{$base}/models/{$model}:generateContent", [
                    'contents' => [['role' => 'user', 'parts' => [
                        ['text' => $this->prompt($request)."\nSchema: ".json_encode(ExtractedPayloadDto::jsonSchema())],
                        ['inline_data' => ['mime_type' => $request->mimeType, 'data' => base64_encode($request->contents)]],
                    ]]],
                    'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json'],
                ]);
        } catch (ConnectionException $exception) {
            throw new OcrProviderException('Gemini is not reachable: '.$exception->getMessage(), 0, $exception);
        }

        if (! $response->successful()) {
            throw new OcrProviderException('Gemini answered HTTP '.$response->status().'.');
        }

        return $this->readAnswer((string) $response->json('candidates.0.content.parts.0.text', ''), (int) $response->json('usageMetadata.totalTokenCount', 0));
    }
}
