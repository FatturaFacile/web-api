<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Providers;

use Modules\OcrExtraction\Domain\Contracts\OcrVisionProviderInterface;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Domain\Exceptions\OcrProviderException;
use Modules\OcrExtraction\Domain\ValueObjects\BoundingBoxDto;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionRequest;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionResult;
use Modules\OcrExtraction\Infrastructure\Services\DocumentRasterizer;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Lightweight local fallback: the `tesseract` binary reads each page (TSV with word boxes) and HeuristicInvoiceTextParser
 * extracts the fields with patterns. It needs no model and sends nothing anywhere. A field's box is the OCR line that holds
 * its value.
 */
final class TesseractOcrAdapter implements OcrVisionProviderInterface
{
    public function __construct(
        private readonly DocumentRasterizer $rasterizer,
        private readonly HeuristicInvoiceTextParser $parser,
    ) {}

    public function type(): OcrProviderType
    {
        return OcrProviderType::TesseractLocal;
    }

    public function extract(OcrExtractionRequest $request): OcrExtractionResult
    {
        $pages = $this->rasterizer->pages($request->contents, $request->mimeType);

        if ($pages === []) {
            throw new OcrProviderException('The document could not be converted to page images (install Imagick or poppler-utils).');
        }

        $text = '';
        $lineBoxes = [];

        foreach ($pages as $index => $page) {
            $tsv = $this->recognize($page['data'], (string) ($request->settings['languages'] ?? 'ita+deu+fra+eng'));
            [$pageText, $boxes] = $this->readTsv($tsv, $index + 1);
            $text .= $pageText."\n";
            $lineBoxes = [...$lineBoxes, ...$boxes];
        }

        $payload = $this->parser->parse($text);

        return new OcrExtractionResult($payload, $this->locate($payload, $lineBoxes), $this->type(), 0);
    }

    private function recognize(string $image, string $languages): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ocr');

        if ($file === false) {
            throw new OcrProviderException('A temporary file could not be created.');
        }

        try {
            file_put_contents($file, $image);
            $process = new Process([(string) config('ocr_extraction.tesseract.binary', 'tesseract'), $file, 'stdout', '-l', $languages, 'tsv'], null, null, null, (int) config('ocr_extraction.timeout_seconds', 120));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new OcrProviderException('Tesseract failed: '.trim($process->getErrorOutput()));
            }

            return $process->getOutput();
        } catch (OcrProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OcrProviderException('Tesseract is not available: '.$exception->getMessage(), 0, $exception);
        } finally {
            @unlink($file);
        }
    }

    /**
     * Text of a page and the box of each of its lines.
     *
     * @return array{0: string, 1: list<array{text: string, box: BoundingBoxDto}>}
     */
    private function readTsv(string $tsv, int $page): array
    {
        $width = 0;
        $height = 0;
        $lines = [];

        foreach (preg_split('/\R/', trim($tsv)) ?: [] as $row) {
            $cells = explode("\t", $row);

            if (count($cells) < 12 || $cells[0] === 'level') {
                continue;
            }

            [$level, , $block, $paragraph, $line, , $left, $top, $w, $h, $conf, $word] = array_slice($cells, 0, 12);

            if ((int) $level === 1) {
                $width = (int) $w;
                $height = (int) $h;

                continue;
            }

            if ((int) $level !== 5 || trim($word) === '' || (float) $conf < 0) {
                continue;
            }

            $key = "{$block}.{$paragraph}.{$line}";
            $lines[$key]['words'][] = $word;
            $lines[$key]['x0'] = min($lines[$key]['x0'] ?? PHP_INT_MAX, (int) $left);
            $lines[$key]['y0'] = min($lines[$key]['y0'] ?? PHP_INT_MAX, (int) $top);
            $lines[$key]['x1'] = max($lines[$key]['x1'] ?? 0, (int) $left + (int) $w);
            $lines[$key]['y1'] = max($lines[$key]['y1'] ?? 0, (int) $top + (int) $h);
        }

        $text = '';
        $boxes = [];

        foreach ($lines as $line) {
            $content = implode(' ', $line['words']);
            $text .= $content."\n";

            $box = $width > 0 && $height > 0 ? BoundingBoxDto::tryFromArray([
                'page' => $page,
                'x' => $line['x0'] / $width,
                'y' => $line['y0'] / $height,
                'width' => ($line['x1'] - $line['x0']) / $width,
                'height' => ($line['y1'] - $line['y0']) / $height,
            ]) : null;

            if ($box !== null) {
                $boxes[] = ['text' => $content, 'box' => $box];
            }
        }

        return [$text, $boxes];
    }

    /**
     * @param  list<array{text: string, box: BoundingBoxDto}>  $lineBoxes
     * @return array<string, BoundingBoxDto>
     */
    private function locate(ExtractedPayloadDto $payload, array $lineBoxes): array
    {
        $values = [];

        foreach ($payload->vendor as $name => $value) {
            $values['vendor.'.$name] = $value;
        }

        foreach ($payload->document as $name => $value) {
            $values['header.'.($name === 'number' ? 'document_number' : $name)] = $value;
        }

        foreach ($payload->lines as $index => $line) {
            $values["lines[{$index}].description"] = $line['description'];
        }

        $boxes = [];

        foreach ($values as $key => $value) {
            if ($value === null || $value === '' || str_contains($key, 'date')) {
                continue;
            }

            $needle = preg_replace('/\s+/', '', (string) $value) ?? '';

            foreach ($lineBoxes as $line) {
                if ($needle !== '' && str_contains(preg_replace('/\s+/', '', $line['text']) ?? '', $needle)) {
                    $boxes[$key] = $line['box'];

                    break;
                }
            }
        }

        return $boxes;
    }
}
