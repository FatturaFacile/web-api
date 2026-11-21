<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\Contracts;

use Modules\SwissFiscal\Domain\ValueObjects\FiscalDocument;

/**
 * Strategy for rendering a country-specific fiscal document (FatturaPA XML for Italy, QR-bill for Switzerland).
 * Implementations are registered by country code and stay independent of one another.
 */
interface FiscalDocumentGeneratorInterface
{
    /** Upper-case ISO 3166 alpha-2 country this strategy serves. */
    public function country(): string;

    /** Format identifier of the produced document, e.g. "fatturapa-xml" or "swiss-qr-bill". */
    public function format(): string;

    /**
     * @param  array<string, mixed>  $context  Strategy-specific input (for Italy `invoice_id`, for Switzerland the QR-bill fields).
     */
    public function generate(string $tenantId, array $context): FiscalDocument;
}
