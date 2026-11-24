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
use Modules\OcrExtraction\Infrastructure\Services\DocumentRasterizer;

/**
 * OpenAI vision chat completions (cloud). Only used when the tenant consented to external AI providers and stored its own
 * API key. Pages are sent as images; the answer is requested as a JSON object following the payload schema.
 */
final class OpenAiVisionAdapter extends AbstractVisionAdapter
{
    public function __construct(private readonly DocumentRasterizer $rasterizer) {}

    public function type(): OcrProviderType
    {
        return OcrProviderType::OpenAiCloud;
    }

    public function extract(OcrExtractionRequest $request): OcrExtractionResult
    {
        $key = $this->requiredSetting($request->settings, 'api_key');
        $pages = $this->rasterizer->pages($request->contents, $request->mimeType);

        if ($pages === []) {
            throw new OcrProviderException('The document could not be converted to page images (install Imagick or poppler-utils).');
        }

        $content = [['type' => 'text', 'text' => $this->prompt($request)."\nSchema: ".json_encode(ExtractedPayloadDto::jsonSchema())]];

        foreach ($pages as $page) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$page['mime'].';base64,'.base64_encode($page['data'])]];
        }

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('ocr_extraction.timeout_seconds', 120))
                ->post(rtrim((string) config('ocr_extraction.openai.endpoint'), '/').'/chat/completions', [
                    'model' => $this->setting($request->settings, 'model', (string) config('ocr_extraction.openai.model')),
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [['role' => 'user', 'content' => $content]],
                ]);
        } catch (ConnectionException $exception) {
            throw new OcrProviderException('OpenAI is not reachable: '.$exception->getMessage(), 0, $exception);
        }

        if (! $response->successful()) {
            throw new OcrProviderException('OpenAI answered HTTP '.$response->status().'.');
        }

        return $this->readAnswer((string) $response->json('choices.0.message.content', ''), (int) $response->json('usage.total_tokens', 0));
    }
}
