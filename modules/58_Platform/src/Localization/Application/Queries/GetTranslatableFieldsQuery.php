<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

use Modules\Platform\Localization\Domain\Enums\TranslatableResource;

final readonly class GetTranslatableFieldsQuery
{
    public function __construct(public string $tenantId, public TranslatableResource $resource, public string $id) {}
}
