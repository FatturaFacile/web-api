<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Commands;

use App\Models\User;

/**
 * Saves a report definition, new (`$id` null) or an existing one. `$definition` carries `dataset`, `spec`, `pivot`,
 * `calculated_fields`, `conditional_formatting` and `chart_config`.
 */
final readonly class CreateOrUpdateSavedReportCommand
{
    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $sharedRoles
     * @param  list<string>  $sharedWith
     */
    public function __construct(
        public User $user,
        public ?string $id,
        public string $name,
        public ?string $description,
        public array $definition,
        public string $visibility,
        public array $sharedRoles = [],
        public array $sharedWith = [],
    ) {}
}
