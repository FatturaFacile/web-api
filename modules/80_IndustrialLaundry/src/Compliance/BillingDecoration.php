<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Compliance;

/**
 * What the billing strategies add to the invoice: the fiscal document type (TD24 deferred invoice in Italy), the payment
 * flavour (QR-bill in Switzerland), the notes and the reporting sections stored with the billing run.
 */
final readonly class BillingDecoration
{
    /**
     * @param  list<array{key: string, params: array<string, string|int|float|null>}>  $notes  Translation keys (industrial_laundry.invoice.*) and their parameters.
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public ?string $documentType = null,
        public ?string $paymentFlavour = null,
        public array $notes = [],
        public array $report = [],
    ) {}

    /**
     * @param  list<array{key: string, params: array<string, string|int|float|null>}>  $notes
     * @param  array<string, mixed>  $report
     */
    public function with(?string $documentType = null, ?string $paymentFlavour = null, array $notes = [], array $report = []): self
    {
        return new self(
            $documentType ?? $this->documentType,
            $paymentFlavour ?? $this->paymentFlavour,
            [...$this->notes, ...$notes],
            [...$this->report, ...$report],
        );
    }
}
