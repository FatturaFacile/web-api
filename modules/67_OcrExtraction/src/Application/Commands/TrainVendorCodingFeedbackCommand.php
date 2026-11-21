<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Commands;

/**
 * Teaches the vendor coding model what the accountant confirmed or corrected.
 */
final readonly class TrainVendorCodingFeedbackCommand
{
    /**
     * @param  list<array{prediction_model_id: string|null, gl_account_id: string|null, vat_code_id: string|null, cost_center_id: string|null}>  $originalSuggestions  what the AI suggested per line, in line order
     * @param  list<array{description: string, gl_account_id: string, vat_code_id: string, cost_center_id: string|null}>  $confirmedLines  what the accountant confirmed per line, in line order
     */
    public function __construct(
        public string $tenantId,
        public string $vendorId,
        public array $originalSuggestions,
        public array $confirmedLines,
    ) {}
}
