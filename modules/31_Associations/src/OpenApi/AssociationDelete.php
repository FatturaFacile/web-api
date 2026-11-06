<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use Attribute;
use OpenApi\Attributes as OA;

/**
 * A DELETE operation of the module.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AssociationDelete extends OA\Delete
{
    public function __construct(string $path, string $id, string $summary, string $audience = OperationArguments::Staff)
    {
        parent::__construct(...OperationArguments::for($path, $id, $summary, $audience, null, [], false, false));
    }
}
