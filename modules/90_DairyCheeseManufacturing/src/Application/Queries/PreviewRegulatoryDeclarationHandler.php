<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Queries;

use Modules\DairyCheeseManufacturing\Services\Regulatory\RegulatoryService;

final class PreviewRegulatoryDeclarationHandler
{
    public function __construct(private readonly RegulatoryService $regulatory) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(PreviewRegulatoryDeclarationQuery $query): array
    {
        return $this->regulatory->preview($query->authority, $query->period);
    }
}
