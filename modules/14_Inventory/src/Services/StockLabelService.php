<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Enums\BarcodeType;
use App\Services\Inventory\BarcodeService;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockPackage;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Inventory\Models\WarehouseBin;

/**
 * Barcodes for what the warehouse handles. An item prints its EAN-13 when asked for one, otherwise its own code or SKU;
 * lots, serials, locations and packages print their identifier.
 */
final class StockLabelService
{
    public function __construct(
        private readonly BarcodeService $barcodes
    ) {}

    /**
     * @return array{target: string, id: string, symbology: string, value: string, format: string, content: string}
     */
    public function render(string $target, string $id, BarcodeType $symbology, string $format): array
    {
        $value = $this->valueOf($target, $id, $symbology);
        $content = $this->barcodes->render($symbology, $value, $format);

        return [
            'target' => $target,
            'id' => $id,
            'symbology' => $symbology->value,
            'value' => $this->barcodes->normalize($symbology, $value),
            'format' => $format,
            'content' => $format === BarcodeService::FORMAT_PNG ? base64_encode($content) : $content,
        ];
    }

    private function valueOf(string $target, string $id, BarcodeType $symbology): string
    {
        $value = match ($target) {
            'item' => $this->itemCode(StockItem::query()->findOrFail($id), $symbology),
            'lot' => StockLot::query()->findOrFail($id)->lot_number,
            'serial' => StockSerialNumber::query()->findOrFail($id)->serial_number,
            'bin' => WarehouseBin::query()->findOrFail($id)->barcode,
            'package' => StockPackage::query()->findOrFail($id)->package_number,
            default => null,
        };

        if ($value === null || $value === '') {
            throw new StockRuleViolation("There is no code to print for this {$target}.");
        }

        return (string) $value;
    }

    private function itemCode(StockItem $item, BarcodeType $symbology): ?string
    {
        return $symbology === BarcodeType::Ean13 ? $item->barcode_ean13 : ($item->barcode_custom ?? $item->sku);
    }
}
