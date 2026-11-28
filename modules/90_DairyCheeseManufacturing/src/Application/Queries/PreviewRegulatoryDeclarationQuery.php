<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Queries;

use Modules\DairyCheeseManufacturing\Domain\Enums\Authority;

final readonly class PreviewRegulatoryDeclarationQuery
{
    public function __construct(public Authority $authority, public string $period) {}
}
