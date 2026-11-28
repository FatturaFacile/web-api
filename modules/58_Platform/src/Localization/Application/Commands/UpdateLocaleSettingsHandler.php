<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Commands;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Platform\Localization\Domain\Events\LocaleSettingsChanged;
use Modules\Platform\Localization\Domain\ValueObjects\LocaleSettings;
use Modules\Platform\Localization\Infrastructure\Persistence\TenantLocaleSettingsRepository;

final class UpdateLocaleSettingsHandler
{
    public function __construct(private readonly TenantLocaleSettingsRepository $settings, private readonly Dispatcher $events) {}

    public function __invoke(UpdateLocaleSettingsCommand $command): LocaleSettings
    {
        $this->settings->save($command->tenantId, $command->settings);
        $this->events->dispatch(new LocaleSettingsChanged($command->tenantId));

        return $command->settings;
    }
}
