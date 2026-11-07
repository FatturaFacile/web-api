<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use Attribute;
use OpenApi\Attributes as OA;

/**
 * A PUT operation of the module.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AssociationPut extends OA\Put
{
    public function __construct(string $path, string $id, string $summary, ?string $body = null, string $audience = OperationArguments::Staff, ?string $response = null)
    {
        parent::__construct(...OperationArguments::for($path, $id, $summary, $audience, $body, [], false, false, $response));
    }
}
