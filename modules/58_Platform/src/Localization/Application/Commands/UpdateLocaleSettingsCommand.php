<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Commands;

use Modules\Platform\Localization\Domain\ValueObjects\LocaleSettings;

final readonly class UpdateLocaleSettingsCommand
{
    public function __construct(public string $tenantId, public LocaleSettings $settings) {}
}
