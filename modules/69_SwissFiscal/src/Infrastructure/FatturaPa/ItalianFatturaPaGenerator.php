<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Infrastructure\FatturaPa;

use App\Models\Invoice;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use Modules\SDI\Services\FatturaPaSchemaValidator;
use Modules\SwissFiscal\Domain\Contracts\FiscalDocumentGeneratorInterface;
use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;
use Modules\SwissFiscal\Domain\ValueObjects\FiscalDocument;

/**
 * Italian strategy: wraps the existing FatturaPA builder (split payment, reverse charge, bollo, withholding, Codice
 * Destinatario/PEC routing) and validates the XML against the official XSD before handing it out.
 */
final class ItalianFatturaPaGenerator implements FiscalDocumentGeneratorInterface
{
    public function __construct(
        private readonly FatturaPaBuilderService $builder,
        private readonly FatturaPaSchemaValidator $validator,
    ) {}

    public function country(): string
    {
        return 'IT';
    }

    public function format(): string
    {
        return 'fatturapa-xml';
    }

    public function generate(string $tenantId, array $context): FiscalDocument
    {
        $invoice = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereKey((string) ($context['invoice_id'] ?? ''))
            ->first();

        if (! $invoice instanceof Invoice) {
            throw new SwissFiscalException('Unknown invoice.');
        }

        $xml = $this->builder->buildFatturaPaXml($invoice);
        $errors = $this->validator->errors($xml);

        if ($errors !== []) {
            throw new SwissFiscalException('The FatturaPA XML violates the schema: '.implode(' | ', array_slice($errors, 0, 5)));
        }

        return new FiscalDocument($this->format(), 'application/xml', $xml, 'fattura-'.$invoice->getKey().'.xml', ['valid' => true]);
    }
}
