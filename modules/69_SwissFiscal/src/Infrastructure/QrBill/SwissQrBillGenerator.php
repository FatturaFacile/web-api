<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Infrastructure\QrBill;

use Modules\Observability\Metrics\DomainMetrics;
use Modules\Observability\Metrics\MetricCatalog;
use Modules\SwissFiscal\Domain\Contracts\FiscalDocumentGeneratorInterface;
use Modules\SwissFiscal\Domain\Enums\QrReferenceType;
use Modules\SwissFiscal\Domain\Services\QrBillPayloadBuilder;
use Modules\SwissFiscal\Domain\ValueObjects\Currency;
use Modules\SwissFiscal\Domain\ValueObjects\FiscalDocument;
use Modules\SwissFiscal\Domain\ValueObjects\Money;
use Modules\SwissFiscal\Domain\ValueObjects\QrBill;
use Modules\SwissFiscal\Domain\ValueObjects\QrBillAddress;

/**
 * Swiss strategy: turns the QR-bill fields into a PDF payment slip. The payload and SVG are returned in the metadata so
 * offline clients can render the slip themselves.
 */
final class SwissQrBillGenerator implements FiscalDocumentGeneratorInterface
{
    public function __construct(
        private readonly QrBillPayloadBuilder $payloads,
        private readonly QrBillRenderer $renderer,
    ) {}

    public function country(): string
    {
        return 'CH';
    }

    public function format(): string
    {
        return 'swiss-qr-bill';
    }

    public function generate(string $tenantId, array $context): FiscalDocument
    {
        return DomainMetrics::time(MetricCatalog::QR_BILL_DURATION, fn (): FiscalDocument => $this->render($context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function render(array $context): FiscalDocument
    {
        $bill = $this->billFrom($context);
        $payload = $this->payloads->build($bill);
        $language = is_string($context['language'] ?? null) ? $context['language'] : 'en';

        return new FiscalDocument(
            $this->format(),
            'application/pdf',
            $this->renderer->slipPdf($bill, $payload, $language),
            'qr-bill-'.($bill->reference ?? 'open').'.pdf',
            ['payload' => $payload, 'qr_svg' => $this->renderer->qrSvg($payload), 'reference_type' => $bill->referenceType->value],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function billFrom(array $context): QrBill
    {
        $currency = Currency::from((string) $context['currency']);
        $amount = isset($context['amount'])
            ? Money::of((string) $context['amount'], $currency)
            : null;

        /** @var array<string, string> $creditor */
        $creditor = $context['creditor'];
        $debtor = $context['debtor'] ?? null;

        return new QrBill(
            (string) $context['iban'],
            $this->address($creditor),
            $currency,
            $amount,
            QrReferenceType::from((string) $context['reference_type']),
            isset($context['reference']) ? (string) $context['reference'] : null,
            is_array($debtor) ? $this->address($debtor) : null,
            (string) ($context['message'] ?? ''),
            (string) ($context['bill_information'] ?? ''),
        );
    }

    /**
     * @param  array<string, string>  $data
     */
    private function address(array $data): QrBillAddress
    {
        return new QrBillAddress(
            $data['name'],
            $data['postal_code'],
            $data['town'],
            strtoupper($data['country']),
            $data['street'] ?? '',
            $data['building_number'] ?? '',
        );
    }
}
