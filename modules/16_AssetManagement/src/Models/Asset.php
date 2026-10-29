<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Database\Factories\AssetFactory;

final class Asset extends FixedAsset
{
    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }
}
