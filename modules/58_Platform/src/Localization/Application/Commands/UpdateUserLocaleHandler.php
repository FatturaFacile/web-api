<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Commands;

use App\Models\User;

final class UpdateUserLocaleHandler
{
    public function __invoke(UpdateUserLocaleCommand $command): string
    {
        $user = User::query()->findOrFail($command->userId);
        $user->forceFill(['locale' => $command->locale->value()])->save();

        return $command->locale->value();
    }
}
