<?php

declare(strict_types=1);

namespace App\Actions\Ui;

use App\Models\User;

class UpdateUserPreferences
{
    /**
     * Save the validated interface preferences of the signed-in user and return the stored state.
     *
     * @param  array<string, mixed>  $validated
     */
    public function execute(User $user, array $validated): User
    {
        $user->fill($validated)->save();

        return $user->refresh();
    }
}
