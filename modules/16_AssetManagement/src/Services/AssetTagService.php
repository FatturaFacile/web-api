<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use Modules\AssetManagement\Models\FixedAsset;

/**
 * Data printed on asset tags: the Code 128 barcode value and a QR payload that identifies the asset without exposing
 * its values, plus the label lines. Rendering is left to the label printer or the client.
 */
final class AssetTagService
{
    /**
     * Tag data of one asset.
     *
     * @return array{asset_id: string, asset_number: string, barcode_value: string, barcode_symbology: string, qr_payload: string, label_lines: list<string>}
     */
    public function tagData(FixedAsset $asset): array
    {
        $barcode = (string) ($asset->barcode_tag ?: $asset->asset_number);
        $payload = json_encode(['type' => 'eam_asset', 'tenant' => (string) $asset->tenant_id, 'id' => (string) $asset->id, 'number' => (string) $asset->asset_number], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'asset_id' => (string) $asset->id,
            'asset_number' => (string) $asset->asset_number,
            'barcode_value' => $barcode,
            'barcode_symbology' => 'code128',
            'qr_payload' => $payload,
            'label_lines' => array_values(array_filter([
                (string) $asset->name,
                (string) $asset->asset_number,
                $asset->serial_number !== null ? 'S/N '.$asset->serial_number : null,
                $asset->location?->name,
            ], static fn (?string $line): bool => $line !== null && $line !== '')),
        ];
    }
}
