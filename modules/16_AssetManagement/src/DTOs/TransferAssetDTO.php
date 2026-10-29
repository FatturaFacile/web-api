<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

final readonly class TransferAssetDTO
{
    public function __construct(
        public string $assetId,
        public ?string $toLocationId = null,
        public ?string $toCustodianId = null,
        public ?string $toProjectId = null,
        public ?string $transferDate = null,
        public ?string $reason = null
    ) {}
}
