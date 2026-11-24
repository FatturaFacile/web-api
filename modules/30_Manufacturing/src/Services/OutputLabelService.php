<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgMaterialMovement;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * GS1-128 label of the finished goods received from a production order: GTIN (AI 01), best-before date (AI 17) and
 * batch (AI 10, last because of its variable length), encoded as Code 128 with the leading FNC1 character.
 */
final class OutputLabelService
{
    public const FNC1 = "\xF1";

    /** Symbology identifier of a GS1-128 symbol, the printable equivalent of the leading FNC1. */
    public const SYMBOLOGY_IDENTIFIER = ']C1';

    /**
     * @return array{production_order_id: string, lot_id: string, lot_number: string, gtin: string, expiration_date: string|null, human_readable: string, data: string, symbology: string, format: string, content: string}
     */
    public function render(MfgProductionOrder $order, string $format): array
    {
        $movement = MfgMaterialMovement::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('production_order_id', $order->id)
            ->where('movement_type', MfgMaterialMovement::Receipt)
            ->whereNotNull('lot_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $movement instanceof MfgMaterialMovement) {
            throw new ManufacturingOperationException('The order has no lot-tracked output receipt to label.', 'lot_id');
        }

        $lot = StockLot::query()->where('tenant_id', $order->tenant_id)->findOrFail($movement->lot_id);
        $stockItem = StockItem::query()->where('tenant_id', $order->tenant_id)->findOrFail($lot->stock_item_id);
        $gtin = $this->gtin14((string) ($stockItem->barcode_ean13 ?? ''));
        $expiry = $lot->expiration_date?->format('ymd');

        $elements = [['01', $gtin]];
        if ($expiry !== null) {
            $elements[] = ['17', $expiry];
        }
        $elements[] = ['10', (string) $lot->lot_number];

        $data = self::FNC1.implode('', array_map(static fn (array $element): string => $element[0].$element[1], $elements));
        $human = implode('', array_map(static fn (array $element): string => '('.$element[0].')'.$element[1], $elements));

        return [
            'production_order_id' => (string) $order->id,
            'lot_id' => (string) $lot->id,
            'lot_number' => (string) $lot->lot_number,
            'gtin' => $gtin,
            'expiration_date' => $lot->expiration_date?->toDateString(),
            'human_readable' => $human,
            // FNC1 is a single non-UTF-8 byte (0xF1): the JSON carries the GS1 symbology identifier ]C1 in its place.
            'data' => self::SYMBOLOGY_IDENTIFIER.substr($data, strlen(self::FNC1)),
            'symbology' => 'gs1-128',
            'format' => $format,
            'content' => $this->draw($data, $format),
        ];
    }

    /**
     * The GTIN-14 of an EAN-13 (indicator digit 0) with its check digit.
     */
    public function gtin14(string $ean13): string
    {
        if (preg_match('/^\d{13}$/', $ean13) !== 1) {
            throw new ManufacturingOperationException('The item has no valid EAN-13 (GTIN) to encode on the label.', 'barcode_ean13');
        }

        $payload = '0'.substr($ean13, 0, 12);
        $sum = 0;
        foreach (str_split(strrev($payload)) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return $payload.((10 - $sum % 10) % 10);
    }

    private function draw(string $data, string $format): string
    {
        if ($format === 'png') {
            return base64_encode((new BarcodeGeneratorPNG)->getBarcode($data, BarcodeGenerator::TYPE_CODE_128, 2, 60));
        }

        return (new BarcodeGeneratorSVG)->getBarcode($data, BarcodeGenerator::TYPE_CODE_128, 2, 60);
    }
}
