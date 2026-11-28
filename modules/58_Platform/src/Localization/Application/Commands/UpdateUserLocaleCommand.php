<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Commands;

use Modules\Platform\Localization\Domain\ValueObjects\Locale;

final readonly class UpdateUserLocaleCommand
{
    public function __construct(public string $userId, public Locale $locale) {}
}
