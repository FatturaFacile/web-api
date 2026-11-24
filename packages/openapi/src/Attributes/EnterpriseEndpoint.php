<?php

declare(strict_types=1);

namespace Enterprise\OpenApi\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class EnterpriseEndpoint
{
    public function __construct(
        public string $summary,
        public ?string $description = null,
        public array $tags = [],
        public bool $deprecated = false,
        public string $version = 'v1'
    ) {}
}
