<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Domain\ValueObjects;

final readonly class ThermalPrintPayload
{
    public function __construct(
        public string $profile,
        public string $printerBrand,
        public string $paperWidthMm,
        public string $documentNumber,
        public string $escposCommands,
        public string $zplCommands,
        public string $humanPreview,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'printer_brand' => $this->printerBrand,
            'paper_width_mm' => $this->paperWidthMm,
            'document_number' => $this->documentNumber,
            'escpos_commands' => $this->escposCommands,
            'zpl_commands' => $this->zplCommands,
            'human_preview' => $this->humanPreview,
        ];
    }
}
