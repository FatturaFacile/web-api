<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Domain\Exceptions\OcrProviderException;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionRequest;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionResult;

/**
 * Local LayoutLMv3 microservice. It receives the file as multipart and answers in the payload schema, with the exact
 * token-level bounding boxes of the model, so field regions are as precise as the OCR tokens.
 */
final class LayoutLmV3Adapter extends AbstractVisionAdapter
{
    public function type(): OcrProviderType
    {
        return OcrProviderType::LayoutLmV3Local;
    }

    public function extract(OcrExtractionRequest $request): OcrExtractionResult
    {
        $endpoint = rtrim($this->requiredSetting($request->settings, 'endpoint'), '/');

        try {
            $response = Http::timeout((int) config('ocr_extraction.timeout_seconds', 120))
                ->attach('file', $request->contents, 'document', ['Content-Type' => $request->mimeType])
                ->post($endpoint.'/extract', ['category' => $request->category->value, 'skip' => json_encode($request->skipFieldKeys)]);
        } catch (ConnectionException $exception) {
            throw new OcrProviderException('The LayoutLMv3 service is not reachable: '.$exception->getMessage(), 0, $exception);
        }

        if (! $response->successful()) {
            throw new OcrProviderException('The LayoutLMv3 service answered HTTP '.$response->status().'.');
        }

        return $this->readAnswer($response->body(), 0);
    }
}
