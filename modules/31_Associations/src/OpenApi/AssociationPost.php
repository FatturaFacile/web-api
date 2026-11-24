<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use Attribute;
use OpenApi\Attributes as OA;

/**
 * A POST operation of the module.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AssociationPost extends OA\Post
{
    public function __construct(string $path, string $id, string $summary, ?string $body = null, string $audience = OperationArguments::Staff, bool $multipart = false, ?string $response = null, bool $file = false)
    {
        parent::__construct(...OperationArguments::for($path, $id, $summary, $audience, $body, [], $multipart, $file, $response));
    }
}
