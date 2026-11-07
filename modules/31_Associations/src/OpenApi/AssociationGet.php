<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use Attribute;
use OpenApi\Attributes as OA;

/**
 * A GET operation of the module.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AssociationGet extends OA\Get
{
    /**
     * @param  list<string>  $query
     */
    public function __construct(string $path, string $id, string $summary, string $audience = OperationArguments::Staff, array $query = [], bool $file = false, ?string $response = null)
    {
        parent::__construct(...OperationArguments::for($path, $id, $summary, $audience, null, $query, false, $file, $response));
    }
}
