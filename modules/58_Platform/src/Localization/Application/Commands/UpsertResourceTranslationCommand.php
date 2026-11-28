<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Commands;

use Modules\Platform\Localization\Domain\Enums\TranslatableResource;

/**
 * Sets the translated fields of one master data record for one or more locales.
 */
final readonly class UpsertResourceTranslationCommand
{
    /**
     * @param  array<string, array<string, string|null>>  $translations  locale code => field => text
     */
    public function __construct(
        public string $tenantId,
        public TranslatableResource $resource,
        public string $id,
        public array $translations,
    ) {}
}
