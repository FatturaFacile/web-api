<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Platform\Localization\Application\Queries\GetLocaleSettingsHandler;
use Modules\Platform\Localization\Domain\ValueObjects\LocaleSettings;

/**
 * @property LocaleSettings $resource
 */
final class LocaleSettingsResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray() + ['available_locales' => GetLocaleSettingsHandler::catalogue()];
    }
}
