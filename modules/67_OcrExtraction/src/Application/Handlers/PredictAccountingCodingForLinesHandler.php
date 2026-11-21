<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Handlers;

use Modules\OcrExtraction\Application\Commands\PredictAccountingCodingForLinesCommand;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionNotFoundException;
use Modules\OcrExtraction\Domain\Services\LineDescriptionMatcher;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\OcrExtraction\Infrastructure\Models\VendorCodingPredictionModel;
use Modules\OcrExtraction\Infrastructure\Services\OcrTenantRunner;

/**
 * Suggests the GL account, VAT code and cost center of each extracted line. The description is reduced to its signature
 * (lowercase, no stop words, dates or quantities) and looked up among what the vendor was coded with before: an exact
 * signature first, then the most similar one (Levenshtein / token cosine), then the vendor's most used coding. The
 * confidence of a suggestion is its similarity weighted by how often that coding was right.
 */
final readonly class PredictAccountingCodingForLinesHandler
{
    /**
     * A vendor-level default is a guess about an unseen line, so it never claims more than this.
     */
    private const float VendorDefaultCeiling = 50.0;

    public function __construct(private OcrTenantRunner $runner, private LineDescriptionMatcher $matcher = new LineDescriptionMatcher) {}

    /**
     * @return list<array<string, mixed>> the lines of the job with their suggestions
     */
    public function handle(PredictAccountingCodingForLinesCommand $command): array
    {
        return $this->runner->run($command->tenantId, function () use ($command): array {
            $job = DocumentExtractionJob::query()->where('tenant_id', $command->tenantId)->find($command->jobId);

            if ($job === null) {
                throw new OcrExtractionNotFoundException('The extraction job does not exist.');
            }

            $payload = $job->extracted_payload_json ?? [];
            $models = VendorCodingPredictionModel::query()
                ->where('tenant_id', $command->tenantId)
                ->where('vendor_id', $command->vendorId)
                ->get();

            $default = $models->sortByDesc(static fn (VendorCodingPredictionModel $model): float => $model->historical_hit_count * (float) $model->accuracy_rate)->first();
            $lines = [];

            foreach ((array) ($payload['lines'] ?? []) as $line) {
                $lines[] = [...$line, ...$this->suggest((string) ($line['description'] ?? ''), $models, $default)];
            }

            $payload['lines'] = $lines;
            $job->update(['extracted_payload_json' => $payload]);

            return $lines;
        });
    }

    /**
     * @param  iterable<VendorCodingPredictionModel>  $models
     * @return array<string, mixed>
     */
    private function suggest(string $description, iterable $models, ?VendorCodingPredictionModel $default): array
    {
        $pattern = $this->matcher->normalize($description);
        $best = null;
        $bestScore = (float) config('ocr_extraction.coding_similarity_threshold', 0.6);
        $source = 'none';

        foreach ($models as $model) {
            $score = $this->matcher->similarity($pattern, $model->line_description_pattern);

            if ($score >= $bestScore && ($best === null || $score > $bestScore || $model->historical_hit_count > $best->historical_hit_count)) {
                $best = $model;
                $bestScore = $score;
                $source = $score >= 1.0 ? 'exact' : 'fuzzy';
            }
        }

        $similarity = $bestScore;

        if ($best === null && $default !== null) {
            $best = $default;
            $source = 'vendor_default';
            $similarity = self::VendorDefaultCeiling / 100;
        }

        if ($best === null) {
            return ['suggested_gl_account_id' => null, 'suggested_vat_code_id' => null, 'suggested_cost_center_id' => null, 'coding_confidence' => 0.0, 'coding_source' => 'none', 'prediction_model_id' => null];
        }

        return [
            'suggested_gl_account_id' => $best->suggested_gl_account_id,
            'suggested_vat_code_id' => $best->suggested_vat_code_id,
            'suggested_cost_center_id' => $best->suggested_cost_center_id,
            'coding_confidence' => round($similarity * (float) $best->accuracy_rate, 2),
            'coding_source' => $source,
            'prediction_model_id' => (string) $best->id,
        ];
    }
}
