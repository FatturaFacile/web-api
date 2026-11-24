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
 * Local, privacy-first default: an Ollama vision model (qwen2.5-vl, llama3.2-vision) on the tenant's own infrastructure,
 * called through /api/chat with the payload JSON Schema as structured output. The document never leaves the network.
 */
final class OllamaVisionAdapter extends AbstractVisionAdapter
{
    public function __construct(private readonly DocumentRasterizer $rasterizer) {}

    public function type(): OcrProviderType
    {
        return OcrProviderType::OllamaLocal;
    }

    public function extract(OcrExtractionRequest $request): OcrExtractionResult
    {
        $pages = $this->rasterizer->pages($request->contents, $request->mimeType);

        if ($pages === []) {
            throw new OcrProviderException('The document could not be converted to page images (install Imagick or poppler-utils).');
        }

        $endpoint = rtrim($this->setting($request->settings, 'endpoint', (string) config('ocr_extraction.ollama.endpoint')), '/');

        try {
            $response = Http::timeout((int) config('ocr_extraction.timeout_seconds', 120))->post($endpoint.'/api/chat', [
                'model' => $this->setting($request->settings, 'model', (string) config('ocr_extraction.ollama.model')),
                'stream' => false,
                'format' => ExtractedPayloadDto::jsonSchema(),
                'options' => ['temperature' => 0],
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->prompt($request),
                    'images' => array_map(static fn (array $page): string => base64_encode($page['data']), $pages),
                ]],
            ]);
        } catch (ConnectionException $exception) {
            throw new OcrProviderException('Ollama is not reachable: '.$exception->getMessage(), 0, $exception);
        }

        if (! $response->successful()) {
            throw new OcrProviderException('Ollama answered HTTP '.$response->status().'.');
        }

        return $this->readAnswer((string) $response->json('message.content', ''), (int) $response->json('prompt_eval_count', 0) + (int) $response->json('eval_count', 0));
    }
}
