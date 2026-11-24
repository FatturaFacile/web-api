<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantPlatformAdminCommand extends Command
{
    protected $signature = 'auth:grant-platform-admin {email : Address of an existing user} {--revoke : Remove the role instead}';

    protected $description = 'Grant or revoke the platform administrator role';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $user->forceFill(['is_platform_admin' => ! $this->option('revoke')])->save();
        $this->info($this->option('revoke') ? 'Platform administrator role revoked.' : 'Platform administrator role granted.');

        return self::SUCCESS;
    }
}
