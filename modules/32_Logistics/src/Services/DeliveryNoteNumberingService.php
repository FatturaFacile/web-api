<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Services\Sales\DocumentNumberingService;
use Modules\Logistics\Enums\DeliveryNoteType;

/**
 * Progressive DDT numbering per tenant, fiscal year and numbering section (e.g. DDT-2026-00001).
 * Atomicity (row lock + upsert) is delegated to {@see DocumentNumberingService}.
 */
final class DeliveryNoteNumberingService
{
    public const PADDING = 5;

    public function __construct(private readonly DocumentNumberingService $numbering) {}

    /**
     * @return array{number: string, sequential_number: int, fiscal_year: int}
     */
    public function next(DeliveryNoteType $type, ?int $fiscalYear = null): array
    {
        return $this->numbering->next($type->numberingSection(), $fiscalYear, self::PADDING);
    }
}
