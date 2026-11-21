<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\OcrExtraction\Application\Commands\TrainVendorCodingFeedbackCommand;
use Modules\OcrExtraction\Domain\Services\LineDescriptionMatcher;
use Modules\OcrExtraction\Infrastructure\Models\VendorCodingPredictionModel;
use Modules\OcrExtraction\Infrastructure\Services\OcrTenantRunner;

/**
 * Feedback loop of the auto-coding. For every confirmed line:
 *  - the accountant kept the suggestion: its model counts a hit and its accuracy is recalculated;
 *  - the accountant changed GL account, VAT code or cost center: the superseded model counts a correction and the new
 *    combination is stored for the line's description signature, so the next invoice of the vendor already predicts it.
 */
final readonly class TrainVendorCodingFeedbackHandler
{
    public function __construct(private OcrTenantRunner $runner, private LineDescriptionMatcher $matcher = new LineDescriptionMatcher) {}

    /**
     * @return array{hits: int, corrections: int, learned: int}
     */
    public function handle(TrainVendorCodingFeedbackCommand $command): array
    {
        return $this->runner->run($command->tenantId, function () use ($command): array {
            $result = ['hits' => 0, 'corrections' => 0, 'learned' => 0];

            DB::transaction(function () use ($command, &$result): void {
                foreach ($command->confirmedLines as $index => $confirmed) {
                    $suggestion = $command->originalSuggestions[$index] ?? null;
                    $modelId = $suggestion['prediction_model_id'] ?? null;
                    $suggested = $modelId !== null ? $this->find($command, $modelId) : null;

                    $accepted = $suggested !== null
                        && $suggestion['gl_account_id'] === $confirmed['gl_account_id']
                        && $suggestion['vat_code_id'] === $confirmed['vat_code_id']
                        && ($suggestion['cost_center_id'] ?? null) === ($confirmed['cost_center_id'] ?? null);

                    if ($accepted) {
                        $this->recordHit($suggested);
                        $result['hits']++;

                        continue;
                    }

                    $pattern = $this->matcher->normalize($confirmed['description']);

                    if ($pattern === '') {
                        continue;
                    }

                    if ($suggested !== null && $suggested->line_description_pattern !== $pattern) {
                        $this->recordCorrection($suggested);
                    }

                    if ($suggested !== null) {
                        $result['corrections']++;
                    }

                    $this->learn($command, $pattern, $confirmed, $suggested);
                    $result['learned']++;
                }
            });

            return $result;
        });
    }

    private function find(TrainVendorCodingFeedbackCommand $command, string $id): ?VendorCodingPredictionModel
    {
        return VendorCodingPredictionModel::query()
            ->where('tenant_id', $command->tenantId)
            ->where('vendor_id', $command->vendorId)
            ->find($id);
    }

    private function recordHit(VendorCodingPredictionModel $model): void
    {
        $hits = $model->historical_hit_count + 1;
        $model->update([
            'historical_hit_count' => $hits,
            'accuracy_rate' => VendorCodingPredictionModel::accuracyFor($hits, $model->correction_count),
            'last_used_at' => now(),
        ]);
    }

    private function recordCorrection(VendorCodingPredictionModel $model): void
    {
        $corrections = $model->correction_count + 1;
        $model->update([
            'correction_count' => $corrections,
            'accuracy_rate' => VendorCodingPredictionModel::accuracyFor($model->historical_hit_count, $corrections),
        ]);
    }

    /**
     * Stores the confirmed coding for the signature. When that signature already had a different coding, it is the
     * superseded model: it keeps its correction history (plus this correction) and restarts with one hit.
     *
     * @param  array{description: string, gl_account_id: string, vat_code_id: string, cost_center_id: string|null}  $confirmed
     */
    private function learn(TrainVendorCodingFeedbackCommand $command, string $pattern, array $confirmed, ?VendorCodingPredictionModel $suggested): void
    {
        $existing = VendorCodingPredictionModel::query()
            ->where('tenant_id', $command->tenantId)
            ->where('vendor_id', $command->vendorId)
            ->where('line_description_pattern', $pattern)
            ->first();

        $coding = [
            'suggested_gl_account_id' => $confirmed['gl_account_id'],
            'suggested_vat_code_id' => $confirmed['vat_code_id'],
            'suggested_cost_center_id' => $confirmed['cost_center_id'] ?? null,
        ];

        if ($existing === null) {
            VendorCodingPredictionModel::create([
                'tenant_id' => $command->tenantId,
                'vendor_id' => $command->vendorId,
                'line_description_pattern' => $pattern,
                ...$coding,
                'historical_hit_count' => 1,
                'correction_count' => 0,
                'accuracy_rate' => 100,
                'last_used_at' => now(),
            ]);

            return;
        }

        $sameCoding = $existing->suggested_gl_account_id === $coding['suggested_gl_account_id']
            && $existing->suggested_vat_code_id === $coding['suggested_vat_code_id']
            && $existing->suggested_cost_center_id === $coding['suggested_cost_center_id'];

        if ($sameCoding) {
            $this->recordHit($existing);

            return;
        }

        $corrections = $existing->correction_count + ($suggested?->is($existing) === true ? 1 : 0);
        $existing->update([
            ...$coding,
            'historical_hit_count' => 1,
            'correction_count' => $corrections,
            'accuracy_rate' => VendorCodingPredictionModel::accuracyFor(1, $corrections),
            'last_used_at' => now(),
        ]);
    }
}
